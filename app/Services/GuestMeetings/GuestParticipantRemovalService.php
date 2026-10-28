<?php

namespace App\Services\GuestMeetings;

use App\Enums\MeetingProviderState;
use App\Jobs\ReconcileGuestMeetingParticipantRemoval;
use App\Models\GuestMeetingParticipantRemoval;
use App\Models\GuestMeetingSession;
use App\Models\MeetingParticipant;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Durable, server-derived reconciliation of one revoked guest's LiveKit presence. */
final class GuestParticipantRemovalService
{
    public function __construct(private LiveKitRoomManager $rooms, private AuditLogger $audit) {}

    public function request(GuestMeetingSession $session, ?MeetingParticipant $participant): ?GuestMeetingParticipantRemoval
    {
        if (! $participant || $participant->meeting_id !== $session->meeting_id || $participant->user_id !== null || ! $session->meeting) {
            return null;
        }

        $removal = GuestMeetingParticipantRemoval::query()->firstOrCreate(
            ['guest_meeting_session_id' => $session->id],
            [
                'meeting_id' => $session->meeting_id,
                'meeting_participant_id' => $participant->id,
                'room_name' => $session->meeting->livekit_room_name,
                'participant_identity' => $participant->livekit_identity,
                'next_attempt_at' => now(),
            ],
        );

        // afterCommit prevents any provider operation from observing an
        // uncommitted revocation. A lost dispatch is recovered by the schedule.
        ReconcileGuestMeetingParticipantRemoval::dispatch($removal->id)->afterCommit();

        return $removal;
    }

    /** @return array{attempted: bool, resolved: bool, retry_at: ?\DateTimeInterface} */
    public function attempt(int $removalId): array
    {
        $claimed = DB::transaction(function () use ($removalId) {
            $removal = GuestMeetingParticipantRemoval::query()
                ->with(['session', 'meeting', 'participant'])
                ->lockForUpdate()
                ->find($removalId);
            if (! $removal || $removal->resolved_at || $removal->permanently_failed_at || ! $this->isDue($removal)) {
                return null;
            }
            if ($removal->attempt_count >= $this->maxAttempts()) {
                $this->permanentlyFail($removal, 'attempt_limit_reached');

                return null;
            }
            if (! $this->targetIsStillExact($removal)) {
                $this->permanentlyFail($removal, 'target_mismatch');

                return null;
            }

            $token = (string) Str::uuid();
            $now = now();
            $removal->update([
                'attempt_count' => $removal->attempt_count + 1,
                'last_attempt_at' => $now,
                'attempt_started_at' => $now,
                'attempt_token' => $token,
                'next_attempt_at' => $now->copy()->addSeconds($this->leaseSeconds()),
            ]);

            return [$removal->id, $token, $removal->room_name, $removal->participant_identity];
        });

        if (! $claimed) {
            return ['attempted' => false, 'resolved' => false, 'retry_at' => null];
        }

        [, $token, $roomName, $identity] = $claimed;
        $failure = null;
        try {
            $resolved = $this->rooms->removeParticipant($roomName, $identity) === MeetingProviderState::Ended;
            if (! $resolved) {
                $failure = 'provider_unknown';
            }
        } catch (Throwable $exception) {
            $resolved = false;
            $failure = 'provider_exception:'.$exception::class;
        }

        return DB::transaction(function () use ($removalId, $token, $resolved, $failure) {
            $removal = GuestMeetingParticipantRemoval::query()->with('session')->lockForUpdate()->find($removalId);
            // A lease may have lapsed while an I/O call was stuck. A stale result
            // never overwrites the current claimant's state.
            if (! $removal || $removal->attempt_token !== $token) {
                return ['attempted' => true, 'resolved' => false, 'retry_at' => null];
            }
            if ($resolved) {
                $removal->update(['resolved_at' => now(), 'attempt_started_at' => null, 'attempt_token' => null, 'last_failure' => null]);

                return ['attempted' => true, 'resolved' => true, 'retry_at' => null];
            }

            if ($removal->attempt_count >= $this->maxAttempts()) {
                $this->permanentlyFail($removal, $failure ?? 'provider_unknown');

                return ['attempted' => true, 'resolved' => false, 'retry_at' => null];
            }

            $retryAt = now()->addSeconds($this->backoffFor($removal->attempt_count));
            $removal->update([
                'attempt_started_at' => null,
                'attempt_token' => null,
                'next_attempt_at' => $retryAt,
                'last_failure' => $failure ?? 'provider_unknown',
            ]);
            $this->audit->log('guest-meeting.provider-removal-pending', $removal->session, [], [
                'meeting_id' => $removal->meeting_id,
                'removal_id' => $removal->id,
                'attempt_count' => $removal->attempt_count,
                'failure' => $removal->last_failure,
            ]);

            return ['attempted' => true, 'resolved' => false, 'retry_at' => $retryAt];
        });
    }

    /** @return array{inspected: int, attempted: int, resolved: int} */
    public function reconcileDue(int $limit): array
    {
        $now = now();
        $leaseCutoff = $now->copy()->subSeconds($this->leaseSeconds());
        $ids = GuestMeetingParticipantRemoval::query()
            ->whereNull('resolved_at')->whereNull('permanently_failed_at')
            ->where(function ($query) use ($now, $leaseCutoff) {
                $query->where('next_attempt_at', '<=', $now)
                    ->orWhere('attempt_started_at', '<=', $leaseCutoff);
            })
            ->orderBy('id')->limit($limit)->pluck('id');
        $result = ['inspected' => $ids->count(), 'attempted' => 0, 'resolved' => 0];
        foreach ($ids as $id) {
            $attempt = $this->attempt($id);
            $result['attempted'] += (int) $attempt['attempted'];
            $result['resolved'] += (int) $attempt['resolved'];
        }

        return $result;
    }

    private function isDue(GuestMeetingParticipantRemoval $removal): bool
    {
        return ($removal->attempt_started_at && $removal->attempt_started_at->lte(now()->subSeconds($this->leaseSeconds())))
            || (! $removal->attempt_started_at && (! $removal->next_attempt_at || $removal->next_attempt_at->lte(now())));
    }

    private function targetIsStillExact(GuestMeetingParticipantRemoval $removal): bool
    {
        return $removal->session !== null
            && $removal->meeting !== null
            && $removal->participant !== null
            && $removal->session->meeting_id === $removal->meeting_id
            && $removal->session->meeting_participant_id === $removal->meeting_participant_id
            && $removal->participant->meeting_id === $removal->meeting_id
            && $removal->participant->user_id === null
            && hash_equals($removal->room_name, $removal->meeting->livekit_room_name)
            && hash_equals($removal->participant_identity, $removal->participant->livekit_identity);
    }

    private function permanentlyFail(GuestMeetingParticipantRemoval $removal, string $failure): void
    {
        $removal->update(['permanently_failed_at' => now(), 'attempt_started_at' => null, 'attempt_token' => null, 'last_failure' => $failure]);
        $this->audit->log('guest-meeting.provider-removal-permanently-failed', $removal->session, [], [
            'meeting_id' => $removal->meeting_id,
            'removal_id' => $removal->id,
            'attempt_count' => $removal->attempt_count,
            'failure' => $failure,
        ]);
    }

    private function maxAttempts(): int { return (int) config('guest-meetings.removal_max_attempts', 5); }
    private function leaseSeconds(): int { return (int) config('guest-meetings.removal_lease_seconds', 120); }
    private function backoffFor(int $attempt): int
    {
        $backoff = (array) config('guest-meetings.removal_backoff_seconds', [15, 60, 300, 900]);

        return (int) ($backoff[$attempt - 1] ?? end($backoff));
    }
}

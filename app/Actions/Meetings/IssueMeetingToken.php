<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingParticipantRole;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\IssuedMeetingToken;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IssueMeetingToken
{
    public function __construct(private LiveKitTokenIssuer $issuer, private AuditLogger $audit) {}

    /** @return array{token: IssuedMeetingToken, participant: MeetingParticipant, lifecycle_version: int} */
    public function handle(User $actor, Meeting $meeting): array
    {
        [$participant, $version] = DB::transaction(function () use ($actor, $meeting) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($meeting->id);
            Gate::forUser($actor)->authorize('issueToken', $locked);

            $participant = MeetingParticipant::query()->firstOrNew([
                'meeting_id' => $locked->id,
                'user_id' => $actor->id,
            ]);
            $participant->display_name_snapshot = $actor->name;
            $participant->role = $locked->host_user_id === $actor->id
                ? MeetingParticipantRole::Host : MeetingParticipantRole::Participant;

            $alreadyOccupiesSlot = $participant->exists && (
                $participant->join_reserved_until?->isFuture()
                || $participant->attendanceSessions()->whereNull('left_at')->exists()
            );
            if (! $alreadyOccupiesSlot) {
                $occupied = MeetingParticipant::query()
                    ->where('meeting_id', $locked->id)
                    ->where(function ($query) {
                        $query->where('join_reserved_until', '>', now())
                            ->orWhereHas('attendanceSessions', fn ($sessions) => $sessions->whereNull('left_at'));
                    })->count();
                if ($occupied >= $locked->max_participants) {
                    throw ValidationException::withMessages(['meeting' => 'This meeting has reached capacity.']);
                }
            }

            $participant->join_reserved_until = now()->addSeconds((int) config('livekit.token_ttl_seconds', 300));
            $participant->save();

            return [$participant, $locked->lifecycle_version];
        });

        try {
            $sources = ['camera', 'microphone'];
            if (Gate::forUser($actor)->allows('screenShare', $meeting)) {
                $sources = [...$sources, 'screen_share', 'screen_share_audio'];
            }
            $metadata = json_encode([
                'avatar_url' => $actor->avatarUrl(),
            ], JSON_THROW_ON_ERROR);
            $issued = $this->issuer->issue($meeting->livekit_room_name, $participant->livekit_identity, $participant->display_name_snapshot, $sources, $metadata);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($participant) {
                MeetingParticipant::query()->whereKey($participant->id)->lockForUpdate()->update(['join_reserved_until' => null]);
            });
            throw $exception;
        }

        $this->audit->log('meeting.token-issued', $participant, [], [
            'meeting_id' => $meeting->id, 'role' => $participant->role->value,
            'expires_at' => $issued->expiresAt,
        ]);

        return ['token' => $issued, 'participant' => $participant, 'lifecycle_version' => $version];
    }
}

<?php

namespace App\Actions\Meetings;

use App\Contracts\MeetingLifecycleProvider;
use App\Enums\MeetingProviderState;
use App\Enums\MeetingStatus;
use App\Events\MeetingEnded;
use App\Events\MeetingStarted;
use App\Events\MeetingUpdated;
use App\Models\Meeting;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReconcileMeetingLifecycle
{
    public function __construct(private MeetingLifecycleProvider $provider, private AuditLogger $audit) {}

    public function handle(Meeting $meeting, bool $dryRun = false, ?int $expectedVersion = null): Meeting
    {
        $authoritative = $meeting->fresh();
        if ($expectedVersion !== null && $authoritative->lifecycle_version !== $expectedVersion) {
            return $authoritative;
        }
        if (! in_array($authoritative->status, [MeetingStatus::Starting, MeetingStatus::Ending, MeetingStatus::Active], true)) {
            return $authoritative;
        }

        // Checked before the provider is consulted, so a backstop that is switched
        // off costs no provider calls at all instead of polling every historical
        // Active row once a minute for ever.
        if ($authoritative->status === MeetingStatus::Active && ! $this->activeRecoveryEnabledFor($authoritative)) {
            return $authoritative;
        }

        $providerState = $this->provider->inspect($authoritative);
        if ($dryRun || ! $this->canRecover($authoritative, $providerState)) {
            return $authoritative;
        }

        return DB::transaction(function () use ($authoritative, $providerState, $expectedVersion) {
            $locked = Meeting::query()->lockForUpdate()->findOrFail($authoritative->id);
            if ($expectedVersion !== null && $locked->lifecycle_version !== $expectedVersion) {
                return $locked;
            }
            if ($locked->status === MeetingStatus::Active && ! $this->activeRecoveryEnabledFor($locked)) {
                return $locked;
            }
            if (! $this->canRecover($locked, $providerState)) {
                return $locked;
            }

            $before = $locked->only('status', 'lifecycle_version');
            if ($locked->status === MeetingStatus::Active) {
                $this->endAbandonedActive($locked, $before, $providerState);
            } else {
                if ($locked->status === MeetingStatus::Starting) {
                    $active = $providerState === MeetingProviderState::Active;
                    $locked->update([
                        'status' => $active ? MeetingStatus::Active : MeetingStatus::Scheduled,
                        'actual_start_at' => $active ? ($locked->actual_start_at ?? now()) : null,
                        'session_started_at' => $active ? now() : null,
                        'lifecycle_version' => $locked->lifecycle_version + 1,
                        'last_provider_error' => $active ? null : 'Meeting room does not exist at the provider.',
                    ]);
                    $event = $active ? 'meeting.start-recovered' : 'meeting.start-failed';
                } else {
                    $locked->update([
                        'status' => MeetingStatus::Ended,
                        'actual_end_at' => $locked->actual_end_at ?? now(),
                        'lifecycle_version' => $locked->lifecycle_version + 1,
                        'last_provider_error' => null,
                    ]);
                    $event = 'meeting.end-recovered';
                }
                $this->audit->log($event, $locked, $before, $locked->only('status', 'lifecycle_version', 'actual_start_at', 'actual_end_at'));
            }

            match ($locked->status) {
                MeetingStatus::Active => MeetingStarted::dispatch($locked),
                MeetingStatus::Ended => MeetingEnded::dispatch($locked),
                default => MeetingUpdated::dispatch($locked),
            };

            return $locked;
        });
    }

    /**
     * End a meeting the provider has already ended, without inventing when.
     *
     * The provider abstraction answers a question, not a time: inspect() returns
     * only whether the room is still there, and the room's own disappearance is
     * not an instant this application ever witnessed. The only place a real
     * end time exists is a room_finished webhook, which carries the provider's
     * event timestamp. Absent that, stamping now() would invent an end that
     * minutes or days after the meeting really finished, and every duration
     * derived from it would be wrong by exactly that much.
     *
     * So actual_end_at is deliberately left alone here. It stays NULL, which the
     * schema already permits and the attendance report already handles by
     * reporting the duration as unavailable rather than deriving a percentage
     * from a field nobody can vouch for.
     *
     * The honest limitation is that this is permanent for that meeting. A
     * room_finished webhook arriving later would have carried the exact
     * provider timestamp, but it only rewrites meetings still in Active or
     * Ending, so a row reconciled here keeps its NULL end time. Backfilling it
     * would mean trusting a webhook that arrives out of band with no bound on
     * how late it is, which is a different decision for a different change.
     *
     * The recovery event is the same meeting.end-recovered the Ending path uses,
     * told apart by previous_status, because it means the same thing: the
     * provider's word ended this meeting, not a person's End for everyone.
     */
    private function endAbandonedActive(Meeting $locked, array $before, MeetingProviderState $providerState): void
    {
        $locked->update([
            'status' => MeetingStatus::Ended,
            'lifecycle_version' => $locked->lifecycle_version + 1,
            'last_provider_error' => null,
        ]);
        $this->audit->log('meeting.end-recovered', $locked, $before, [
            'source' => 'provider_reconciliation',
            'previous_status' => MeetingStatus::Active->value,
            'provider_state' => $providerState->value,
            'exact_end_timestamp_available' => false,
            'actual_end_at' => $locked->actual_end_at?->toIso8601String(),
            'observed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Whether this meeting may be ended as an abandoned Active one.
     *
     * Both halves must hold, and a missing or unparseable cutoff disables the
     * recovery rather than defaulting it open: rows that predate the cutoff
     * cannot be judged stale by a rule that did not exist when they ran.
     */
    public function activeRecoveryEnabledFor(Meeting $meeting): bool
    {
        if (! (bool) config('meetings.active_recovery.enabled', false)) {
            return false;
        }

        $cutoff = $this->activeRecoveryCutoff();
        if ($cutoff === null) {
            return false;
        }

        $startedAt = $meeting->actual_start_at ?? $meeting->session_started_at;

        return $startedAt !== null && $startedAt->greaterThanOrEqualTo($cutoff);
    }

    private function activeRecoveryCutoff(): ?Carbon
    {
        $configured = config('meetings.active_recovery.cutoff');

        return is_string($configured) && trim($configured) !== ''
            ? Carbon::parse($configured)
            : null;
    }

    private function canRecover(Meeting $meeting, MeetingProviderState $providerState): bool
    {
        return ($meeting->status === MeetingStatus::Starting
                && $meeting->start_attempt_uuid
                && in_array($providerState, [MeetingProviderState::Active, MeetingProviderState::Ended], true))
            || ($meeting->status === MeetingStatus::Ending
                && $providerState === MeetingProviderState::Ended)
            || ($meeting->status === MeetingStatus::Active
                && $providerState === MeetingProviderState::Ended);
    }
}

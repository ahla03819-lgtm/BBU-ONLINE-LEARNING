<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Events\MeetingScreenShareRequestChanged;
use App\Jobs\ExpireMeetingScreenShareApproval;
use App\Models\MeetingScreenShareRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DecideMeetingScreenShareRequest
{
    public function __construct(private LiveKitRoomManager $rooms, private AuditLogger $audit) {}

    public function handle(User $actor, MeetingScreenShareRequest $request, MeetingScreenShareRequestStatus $decision): MeetingScreenShareRequest
    {
        // The provider grant cannot participate in the database rollback, so a
        // grant that outlives a failed transaction is withdrawn here instead.
        $granted = null;

        try {
            return DB::transaction(function () use ($actor, $request, $decision, &$granted) {
                $locked = MeetingScreenShareRequest::query()->with(['meeting', 'participant'])->lockForUpdate()->findOrFail($request->id);
                Gate::forUser($actor)->authorize('decideScreenShareRequest', [$locked->meeting, $locked]);
                if ($locked->status !== MeetingScreenShareRequestStatus::Pending || ! in_array($decision, [MeetingScreenShareRequestStatus::Approved, MeetingScreenShareRequestStatus::Rejected], true)) {
                    throw ValidationException::withMessages(['request' => 'This screen sharing request cannot be decided.']);
                }
                if ($decision === MeetingScreenShareRequestStatus::Approved) {
                    $roomName = $locked->meeting->livekit_room_name;
                    $identity = $locked->participant->livekit_identity;
                    $state = $this->rooms->setParticipantScreenSharePermission($roomName, $identity, true);
                    if ($state !== MeetingProviderState::Active) {
                        throw ValidationException::withMessages(['screen_share' => 'Unable to grant screen sharing permission.']);
                    }
                    $granted = ['room' => $roomName, 'identity' => $identity];
                }
                $expiresAt = $decision === MeetingScreenShareRequestStatus::Approved ? now()->addMinutes(2) : null;
                $locked->update([
                    'status' => $decision,
                    'active_slot' => $decision === MeetingScreenShareRequestStatus::Approved ? 1 : null,
                    'decided_at' => now(),
                    'decided_by' => $actor->id,
                    'expires_at' => $expiresAt,
                    'completed_at' => $decision === MeetingScreenShareRequestStatus::Rejected ? now() : null,
                ]);
                $this->audit->log('meeting.screen-share-'.$decision->value, $locked, [], ['meeting_id' => $locked->meeting_id, 'decided_by' => $actor->id]);
                // Broadcast is after-commit, so no client can observe an Approved
                // request whose provider grant is not in place.
                MeetingScreenShareRequestChanged::dispatch($locked);
                if ($expiresAt) {
                    ExpireMeetingScreenShareApproval::dispatch($locked->id)->delay($expiresAt)->afterCommit();
                }

                return $locked;
            });
        } catch (Throwable $failure) {
            if ($granted !== null) {
                $this->withdrawFailedGrant($granted['room'], $granted['identity']);
            }

            throw $failure;
        }
    }

    /**
     * Runs outside the rolled-back transaction. A withdrawal failure is logged
     * rather than swallowed; the request is still Pending, so the host can
     * still decide it, and revoking a grant is idempotent either way.
     */
    private function withdrawFailedGrant(string $roomName, string $identity): void
    {
        $state = $this->rooms->setParticipantScreenSharePermission($roomName, $identity, false);
        if ($state === MeetingProviderState::Unknown) {
            Log::error('Screen sharing approval failed and its provider grant could not be withdrawn.', ['room' => $roomName, 'identity' => $identity]);
        }
    }
}

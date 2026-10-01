<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingProviderState;
use App\Enums\MeetingScreenShareRequestStatus;
use App\Events\MeetingScreenShareRequestChanged;
use App\Models\MeetingScreenShareRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CompleteMeetingScreenShareRequest
{
    public function __construct(private LiveKitRoomManager $rooms, private AuditLogger $audit) {}

    public function handle(User $actor, MeetingScreenShareRequest $request): MeetingScreenShareRequest
    {
        return DB::transaction(function () use ($actor, $request) {
            $locked = MeetingScreenShareRequest::query()->with(['meeting', 'participant'])->lockForUpdate()->findOrFail($request->id);
            Gate::forUser($actor)->authorize('completeScreenShareRequest', [$locked->meeting, $locked]);
            // UI Stop share, the browser's native Stop sharing and the provider
            // track_unpublished event all land here, so this must be idempotent.
            if (! $locked->status->isActive()) {
                return $locked;
            }
            $state = $this->rooms->setParticipantScreenSharePermission($locked->meeting->livekit_room_name, $locked->participant->livekit_identity, false);
            if ($state === MeetingProviderState::Unknown) {
                throw ValidationException::withMessages(['screen_share' => 'Unable to revoke screen sharing permission.']);
            }
            $status = $locked->status === MeetingScreenShareRequestStatus::Pending
                ? MeetingScreenShareRequestStatus::Cancelled : MeetingScreenShareRequestStatus::Consumed;
            $locked->update(['status' => $status, 'active_slot' => null, 'completed_at' => now(), 'expires_at' => null]);
            $this->audit->log('meeting.screen-share-'.$status->value, $locked, [], ['meeting_id' => $locked->meeting_id, 'actor_id' => $actor->id]);
            MeetingScreenShareRequestChanged::dispatch($locked);

            return $locked;
        });
    }
}

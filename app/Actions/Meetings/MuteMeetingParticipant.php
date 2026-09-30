<?php

namespace App\Actions\Meetings;

use App\Enums\MeetingMicrophoneMuteResult;
use App\Models\MeetingParticipant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LiveKit\LiveKitRoomManager;
use Illuminate\Support\Facades\Gate;

final class MuteMeetingParticipant
{
    public function __construct(private LiveKitRoomManager $rooms, private AuditLogger $audit) {}

    public function handle(User $actor, MeetingParticipant $participant): MeetingMicrophoneMuteResult
    {
        Gate::forUser($actor)->authorize('muteParticipant', [$participant->meeting, $participant]);

        $result = $this->rooms->muteParticipantMicrophone(
            $participant->meeting->livekit_room_name,
            $participant->livekit_identity,
        );

        if ($result === MeetingMicrophoneMuteResult::Muted) {
            $this->audit->log('meeting.participant-muted', $participant, [], [
                'meeting_id' => $participant->meeting_id,
                'muted_by' => $actor->id,
            ]);
        }

        return $result;
    }
}

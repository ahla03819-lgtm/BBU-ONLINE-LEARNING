<?php

namespace Database\Factories;

use App\Models\LiveKitWebhookEvent;
use App\Models\MeetingAttendanceSession;
use App\Models\MeetingParticipant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MeetingAttendanceSession> */
class MeetingAttendanceSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meeting_participant_id' => MeetingParticipant::factory(),
            'livekit_participant_sid' => 'PA_'.Str::random(24),
            'join_webhook_event_id' => LiveKitWebhookEvent::factory()->state(['event_type' => 'participant_joined']),
            'leave_webhook_event_id' => null,
            'joined_at' => now(),
            'left_at' => null,
            'leave_reason' => null,
        ];
    }
}

<?php

namespace Database\Factories;

use App\Enums\GuestMeetingSessionStatus;
use App\Models\GuestMeetingInvitation;
use App\Models\GuestMeetingSession;
use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GuestMeetingSession> */
class GuestMeetingSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'invitation_id' => GuestMeetingInvitation::factory(),
            'display_name_snapshot' => fake()->name(),
            'credential_hash' => hash('sha256', fake()->uuid()),
            'status' => GuestMeetingSessionStatus::Pending,
            'expires_at' => now()->addHour(),
            'requested_at' => now(),
        ];
    }

    public function forInvitation(GuestMeetingInvitation $invitation): static
    {
        return $this->state([
            'meeting_id' => $invitation->meeting_id,
            'invitation_id' => $invitation->id,
        ]);
    }
}

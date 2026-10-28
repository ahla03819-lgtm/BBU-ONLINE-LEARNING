<?php

namespace Database\Factories;

use App\Models\GuestMeetingInvitation;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GuestMeetingInvitation> */
class GuestMeetingInvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'created_by' => User::factory(),
            'token_hash' => hash('sha256', fake()->uuid()),
            'version' => 1,
            'expires_at' => now()->addDay(),
        ];
    }
}

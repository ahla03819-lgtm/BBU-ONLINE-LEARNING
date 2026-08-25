<?php

namespace Database\Factories;

use App\Enums\MeetingParticipantRole;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MeetingParticipant> */
class MeetingParticipantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'meeting_id' => Meeting::factory(),
            'user_id' => User::factory(),
            'display_name_snapshot' => fake()->name(),
            'role' => MeetingParticipantRole::Participant,
            'join_reserved_until' => null,
            'first_joined_at' => null,
            'last_left_at' => null,
            'removed_at' => null,
            'removed_by' => null,
            'removal_reason' => null,
        ];
    }

    public function host(): static
    {
        return $this->state(['role' => MeetingParticipantRole::Host]);
    }
}

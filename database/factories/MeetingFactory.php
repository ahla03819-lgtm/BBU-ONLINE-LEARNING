<?php

namespace Database\Factories;

use App\Enums\MeetingJoinPolicy;
use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Meeting> */
class MeetingFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDay();

        return [
            'school_class_id' => SchoolClass::factory(),
            'class_subject_id' => null,
            'created_by' => User::factory(),
            'host_user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addHour(),
            'actual_start_at' => null,
            'actual_end_at' => null,
            'status' => MeetingStatus::Scheduled,
            'join_policy' => MeetingJoinPolicy::ActiveOnly,
            'max_participants' => 50,
            'lifecycle_version' => 0,
            'start_attempt_uuid' => null,
            'last_provider_error' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => MeetingStatus::Active, 'actual_start_at' => now(), 'lifecycle_version' => 1]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\MeetingRecurrenceType;
use App\Enums\MeetingSeriesStatus;
use App\Models\MeetingSeries;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MeetingSeries> */
class MeetingSeriesFactory extends Factory
{
    protected $model = MeetingSeries::class;

    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'class_subject_id' => null,
            'created_by' => User::factory(),
            'host_user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'recurrence_type' => MeetingRecurrenceType::Weekly,
            'weekdays' => null,
            'starts_on' => now()->addDay()->toDateString(),
            'ends_on' => now()->addWeeks(4)->toDateString(),
            'local_start_time' => '09:00:00',
            'duration_minutes' => 60,
            'timezone' => config('calendar.default_timezone'),
            'max_participants' => 50,
            'status' => MeetingSeriesStatus::Active,
            'lifecycle_version' => 0,
        ];
    }
}

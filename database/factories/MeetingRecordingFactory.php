<?php

namespace Database\Factories;

use App\Models\MeetingRecording;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MeetingRecording>
 */
class MeetingRecordingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    protected $model = \App\Models\MeetingRecording::class;

    public function definition(): array
    {
        return [
            'public_uuid' => $this->faker->uuid(),
            'meeting_id' => \App\Models\Meeting::factory(),
            'status' => \App\Enums\MeetingRecordingStatus::Ready,
            'storage_disk' => 'local',
            'storage_path' => 'recordings/'.$this->faker->uuid().'.mp4',
            'started_at' => now(),
            'ready_at' => now(),
        ];
    }
}

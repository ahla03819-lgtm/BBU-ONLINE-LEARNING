<?php

namespace Database\Factories;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MeetingTranscript>
 */
class MeetingTranscriptFactory extends Factory
{
    protected static ?string $speakerIdentityPrefix = null;

    public function definition(): array
    {
        $prefix = static::$speakerIdentityPrefix ??= fake()->unique()->userName();

        return [
            'meeting_id' => Meeting::factory(),
            'speaker_identity' => $prefix.'_'.fake()->unique()->numerify('######'),
            'speaker_display_name' => fake()->name(),
            'original_language' => 'en',
            'original_text' => fake()->sentence(),
            'translated_language' => null,
            'translated_text' => null,
            'started_at' => now(),
            'ended_at' => null,
            'sequence' => 0,
        ];
    }

    public function forMeeting(Meeting $meeting): static
    {
        return $this->state(fn (array $attributes) => ['meeting_id' => $meeting->id]);
    }

    public function speaker(string $identity, string $displayName): static
    {
        return $this->state(fn (array $attributes) => [
            'speaker_identity' => $identity,
            'speaker_display_name' => $displayName,
        ]);
    }

    public function withSequence(int $sequence): static
    {
        return $this->state(fn (array $attributes) => ['sequence' => $sequence]);
    }

    public function translated(string $language, string $text): static
    {
        return $this->state(fn (array $attributes) => [
            'translated_language' => $language,
            'translated_text' => $text,
        ]);
    }
}

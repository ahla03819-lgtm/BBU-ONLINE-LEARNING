<?php

namespace Database\Factories;

use App\Enums\LiveKitWebhookStatus;
use App\Models\LiveKitWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<LiveKitWebhookEvent> */
class LiveKitWebhookEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'event_type' => 'participant_joined',
            'livekit_room_name' => 'edway_'.Str::random(40),
            'participant_identity' => (string) Str::uuid(),
            'participant_sid' => 'PA_'.Str::random(24),
            'occurred_at' => now(),
            'payload_sha256' => hash('sha256', fake()->uuid()),
            'status' => LiveKitWebhookStatus::Pending,
            'attempts' => 0,
            'next_attempt_at' => null,
            'processed_at' => null,
            'processing_error' => null,
        ];
    }
}

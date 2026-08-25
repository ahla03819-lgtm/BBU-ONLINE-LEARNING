<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return ['channel_id' => Channel::factory(), 'sender_id' => User::factory(), 'client_uuid' => (string) Str::uuid(), 'type' => MessageType::Text, 'body' => fake()->sentence(), 'reply_to_id' => null, 'edited_at' => null, 'hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null];
    }
}

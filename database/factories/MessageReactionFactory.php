<?php

namespace Database\Factories;

use App\Enums\ReactionType;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageReaction> */
class MessageReactionFactory extends Factory
{
    public function definition(): array
    {
        return ['message_id' => Message::factory(), 'user_id' => User::factory(), 'reaction' => fake()->randomElement(ReactionType::cases())];
    }
}

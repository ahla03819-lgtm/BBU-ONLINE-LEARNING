<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return ['type' => 'group', 'name' => fake()->words(2, true), 'created_by_user_id' => User::factory()];
    }

    public function direct(): static
    {
        return $this->state(fn () => ['type' => 'direct', 'name' => null, 'direct_pair_key' => null]);
    }
}

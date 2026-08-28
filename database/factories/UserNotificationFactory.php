<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<UserNotification> */
class UserNotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'assignment.published',
            'actor_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'deduplication_key' => fake()->unique()->uuid(),
            'context' => ['title' => fake()->sentence(4)],
            'route_name' => 'dashboard',
            'route_parameters' => [],
            'payload_version' => 1,
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}

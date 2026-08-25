<?php

namespace Database\Factories;

use App\Enums\AnnouncementStatus;
use App\Enums\ChannelType;
use App\Models\Announcement;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return ['channel_id' => Channel::factory()->state(['type' => ChannelType::Announcement, 'default_slot' => 2, 'slug' => 'announcements']), 'author_id' => User::factory(), 'title' => fake()->sentence(5), 'body' => fake()->paragraph(), 'status' => AnnouncementStatus::Draft, 'publish_at' => null, 'published_at' => null, 'expires_at' => null, 'pinned_at' => null, 'archived_by' => null, 'archived_at' => null];
    }

    public function published(): static
    {
        return $this->state(['status' => AnnouncementStatus::Published, 'published_at' => now()]);
    }
}

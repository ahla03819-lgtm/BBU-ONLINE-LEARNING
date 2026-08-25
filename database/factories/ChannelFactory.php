<?php

namespace Database\Factories;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return ['school_class_id' => SchoolClass::factory(), 'class_subject_id' => null, 'name' => $name, 'slug' => Str::slug($name), 'description' => null, 'type' => ChannelType::Custom, 'status' => ChannelStatus::Active, 'default_slot' => null, 'created_by' => User::factory(), 'archived_by' => null, 'archived_at' => null];
    }
}

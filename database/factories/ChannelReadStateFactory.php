<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelReadState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChannelReadState> */
class ChannelReadStateFactory extends Factory
{
    public function definition(): array
    {
        return ['channel_id' => Channel::factory(), 'user_id' => User::factory(), 'last_read_message_id' => null, 'last_read_at' => null];
    }
}

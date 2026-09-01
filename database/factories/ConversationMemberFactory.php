<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationMemberFactory extends Factory
{
    protected $model = ConversationMember::class;

    public function definition(): array
    {
        return ['conversation_id' => Conversation::factory(), 'user_id' => User::factory(), 'role' => 'member', 'joined_at' => now(), 'left_at' => null];
    }
}

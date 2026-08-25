<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MessageAttachment> */
class MessageAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['message_id' => Message::factory(), 'uploaded_by' => User::factory(), 'client_uuid' => Str::uuid(), 'disk' => 'local', 'path' => 'message-attachments/testing/'.Str::uuid(), 'original_name' => 'document.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 12, 'sha256' => hash('sha256', 'test'), 'position' => 0];
    }
}

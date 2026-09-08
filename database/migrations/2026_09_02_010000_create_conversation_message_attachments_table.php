<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_message_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_name', 180);
            $table->string('extension', 12);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('attachment_type', 16);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(
                ['conversation_message_id', 'id'],
                'conversation_message_attachments_message_id_id_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_message_attachments');
    }
};

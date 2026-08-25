<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->restrictOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid')->nullable();
            $table->string('type', 20)->default('text');
            $table->text('body');
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->restrictOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hidden_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'sender_id', 'client_uuid'], 'messages_idempotency_unique');
            $table->index(['channel_id', 'id'], 'messages_timeline_index');
            $table->index(['channel_id', 'created_at', 'id'], 'messages_history_index');
            $table->index(['channel_id', 'hidden_at'], 'messages_visibility_index');
        });

        Schema::create('channel_read_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->restrictOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_read_states');
        Schema::dropIfExists('messages');
    }
};

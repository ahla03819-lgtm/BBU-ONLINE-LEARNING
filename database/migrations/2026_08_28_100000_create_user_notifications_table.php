<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 100);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->nullableMorphs('subject');
            $table->string('deduplication_key', 160);
            $table->json('context')->nullable();
            $table->string('route_name', 180)->nullable();
            $table->json('route_parameters')->nullable();
            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'deduplication_key'], 'user_notifications_recipient_dedupe_unique');
            $table->index(['user_id', 'read_at', 'created_at'], 'user_notifications_recipient_unread_idx');
            $table->index(['user_id', 'created_at', 'id'], 'user_notifications_recipient_feed_idx');
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};

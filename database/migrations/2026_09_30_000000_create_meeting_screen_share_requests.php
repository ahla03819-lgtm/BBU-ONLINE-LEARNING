<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_screen_share_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('meeting_participant_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20);
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['meeting_participant_id', 'active_slot'], 'meeting_screen_share_one_active');
            $table->index(['meeting_id', 'status', 'requested_at'], 'meeting_screen_share_status_idx');
        });

        Schema::table('livekit_webhook_events', function (Blueprint $table) {
            $table->unsignedTinyInteger('track_source')->nullable()->after('participant_sid');
            $table->string('track_sid')->nullable()->after('track_source');
        });
    }

    public function down(): void
    {
        Schema::table('livekit_webhook_events', function (Blueprint $table) {
            $table->dropColumn(['track_source', 'track_sid']);
        });
        Schema::dropIfExists('meeting_screen_share_requests');
    }
};

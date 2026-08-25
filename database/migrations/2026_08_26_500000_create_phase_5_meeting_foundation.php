<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_class_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('actual_end_at')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->string('join_policy', 30)->default('active_only');
            $table->string('livekit_room_name', 128)->unique();
            $table->unsignedSmallInteger('max_participants')->default(50);
            $table->unsignedBigInteger('lifecycle_version')->default(0);
            $table->uuid('start_attempt_uuid')->nullable()->unique();
            $table->string('last_provider_error', 500)->nullable();
            $table->timestamps();

            $table->index(['school_class_id', 'status', 'scheduled_start_at'], 'meetings_class_status_start_idx');
            $table->index(['class_subject_id', 'status'], 'meetings_subject_status_idx');
            $table->index(['status', 'scheduled_start_at'], 'meetings_status_start_idx');
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('livekit_identity');
            $table->string('display_name_snapshot');
            $table->string('role', 20)->default('participant');
            $table->timestamp('join_reserved_until')->nullable();
            $table->timestamp('first_joined_at')->nullable();
            $table->timestamp('last_left_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('removal_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'user_id'], 'meeting_participants_meeting_user_unique');
            $table->unique(['meeting_id', 'livekit_identity'], 'meeting_participants_meeting_identity_unique');
            $table->index(['meeting_id', 'removed_at'], 'meeting_participants_removed_idx');
            $table->index(['meeting_id', 'join_reserved_until'], 'meeting_participants_reservation_idx');
        });

        Schema::create('livekit_webhook_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->string('event_type', 50);
            $table->string('livekit_room_name', 128)->nullable();
            $table->string('participant_identity', 128)->nullable();
            $table->string('participant_sid', 128)->nullable();
            $table->timestamp('occurred_at');
            $table->char('payload_sha256', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_error', 500)->nullable();
            $table->timestamps();

            $table->index('event_type');
            $table->index('livekit_room_name');
            $table->index('status');
            $table->index(['status', 'next_attempt_at'], 'livekit_webhooks_retry_idx');
            $table->index(['livekit_room_name', 'occurred_at'], 'livekit_webhooks_room_time_idx');
            $table->index(['participant_sid', 'occurred_at'], 'livekit_webhooks_participant_time_idx');
        });

        Schema::create('meeting_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_participant_id')->constrained()->restrictOnDelete();
            $table->string('livekit_participant_sid', 128);
            $table->uuid('join_webhook_event_id')->unique();
            $table->uuid('leave_webhook_event_id')->nullable()->unique();
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->string('leave_reason', 50)->nullable();
            $table->timestamps();

            $table->foreign('join_webhook_event_id', 'attendance_join_webhook_fk')->references('event_id')->on('livekit_webhook_events')->restrictOnDelete();
            $table->foreign('leave_webhook_event_id', 'attendance_leave_webhook_fk')->references('event_id')->on('livekit_webhook_events')->restrictOnDelete();
            $table->unique(['meeting_participant_id', 'livekit_participant_sid'], 'attendance_participant_sid_unique');
            $table->index(['meeting_participant_id', 'joined_at'], 'attendance_participant_joined_idx');
            $table->index(['meeting_participant_id', 'left_at'], 'attendance_participant_left_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendance_sessions');
        Schema::dropIfExists('livekit_webhook_events');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};

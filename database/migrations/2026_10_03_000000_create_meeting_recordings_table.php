<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stopped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 30)->default('livekit');
            $table->string('provider_egress_id', 128)->nullable();
            $table->string('status', 20)->default('starting');
            $table->string('stop_reason', 30)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('scheduled_stop_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('layout', 50)->nullable();
            $table->string('provider_output_path', 500)->nullable();
            $table->string('storage_disk', 64)->nullable();
            // Kept within MySQL's 3072-byte utf8mb4 index budget, as elsewhere.
            $table->string('storage_path', 700)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('original_name', 180)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('failure_reason', 500)->nullable();

            /*
             * One live-or-settling recording per meeting, enforced by the database
             * rather than by a check-then-insert in application code. The slot is
             * pinned to 1 only while the recording can still change and is null
             * once it is terminal, and both MySQL and SQLite permit repeated
             * nulls in a unique index, so a meeting can accumulate any number of
             * finished recordings while never holding two active ones.
             */
            $table->unsignedTinyInteger('active_slot')->nullable()->default(1);
            $table->timestamps();

            $table->unique(['meeting_id', 'active_slot'], 'meeting_recordings_one_active');
            $table->index(['meeting_id', 'status'], 'meeting_recordings_meeting_status_idx');
            $table->index(['status', 'scheduled_stop_at'], 'meeting_recordings_deadline_idx');
            $table->index(['status', 'stopped_at'], 'meeting_recordings_settling_idx');
            $table->index('provider_egress_id', 'meeting_recordings_egress_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_recordings');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_meeting_participant_removals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guest_meeting_session_id')->unique('gmpr_session_uq')->constrained(indexName: 'gmpr_session_fk')->restrictOnDelete();
            $table->foreignId('meeting_id')->constrained(indexName: 'gmpr_meeting_fk')->restrictOnDelete();
            $table->foreignId('meeting_participant_id')->unique('gmpr_participant_uq')->constrained(indexName: 'gmpr_participant_fk')->restrictOnDelete();
            // Snapshots are server-derived at revocation time. They ensure an old
            // retry can never be redirected to a newer participant or room.
            $table->string('room_name', 128);
            $table->uuid('participant_identity');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('attempt_started_at')->nullable();
            $table->uuid('attempt_token')->nullable();
            $table->string('last_failure', 191)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('permanently_failed_at')->nullable();
            $table->timestamps();

            $table->index(['resolved_at', 'permanently_failed_at', 'next_attempt_at'], 'guest_removals_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_meeting_participant_removals');
    }
};

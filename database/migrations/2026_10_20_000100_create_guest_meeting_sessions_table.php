<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_meeting_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('invitation_id')->constrained('guest_meeting_invitations')->restrictOnDelete();
            $table->string('display_name_snapshot', 80);
            $table->char('credential_hash', 64)->unique();
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'status', 'expires_at'], 'guest_sessions_meeting_status_expiry_idx');
            $table->index(['invitation_id', 'status'], 'guest_sessions_invitation_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_meeting_sessions');
    }
};

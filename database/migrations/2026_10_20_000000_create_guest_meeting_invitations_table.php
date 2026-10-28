<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_meeting_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'expires_at'], 'guest_invites_meeting_expiry_idx');
            $table->index(['meeting_id', 'revoked_at'], 'guest_invites_meeting_revoked_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_meeting_invitations');
    }
};

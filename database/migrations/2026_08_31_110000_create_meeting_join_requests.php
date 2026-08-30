<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_join_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_uuid')->unique();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->foreignId('requester_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['meeting_id', 'requester_user_id'], 'meeting_join_requests_meeting_requester_unique');
            $table->index(['meeting_id', 'status', 'requested_at'], 'meeting_join_requests_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_join_requests');
    }
};

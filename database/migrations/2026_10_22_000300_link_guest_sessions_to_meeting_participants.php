<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guest_meeting_sessions', function (Blueprint $table) {
            $table->foreignId('meeting_participant_id')
                ->nullable()
                ->unique()
                ->after('invitation_id')
                ->constrained('meeting_participants')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('guest_meeting_sessions', function (Blueprint $table) {
            $table->dropUnique(['meeting_participant_id']);
            $table->dropConstrainedForeignId('meeting_participant_id');
        });
    }
};

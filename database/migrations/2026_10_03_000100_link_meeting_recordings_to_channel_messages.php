<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            /*
             * The channel card points at the recording rather than at a file.
             *
             * Nothing permanent about the stored object ever reaches the message,
             * so the processing-to-ready transition updates this single row in
             * place and the unique key makes a duplicate card impossible even if
             * two stop paths race.
             */
            $table->foreignId('meeting_recording_id')
                ->nullable()
                ->unique('messages_meeting_recording_unique')
                ->constrained('meeting_recordings')
                ->nullOnDelete();
        });

        Schema::table('livekit_webhook_events', function (Blueprint $table) {
            $table->string('egress_id', 128)->nullable();

            $table->index(['egress_id', 'occurred_at'], 'livekit_webhooks_egress_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('livekit_webhook_events', function (Blueprint $table) {
            $table->dropIndex('livekit_webhooks_egress_time_idx');
            $table->dropColumn('egress_id');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_meeting_recording_unique');
            $table->dropColumn('meeting_recording_id');
        });
    }
};
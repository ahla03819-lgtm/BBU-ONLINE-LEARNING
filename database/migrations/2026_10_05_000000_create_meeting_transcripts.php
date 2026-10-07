<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->string('speaker_identity', 128);
            $table->string('speaker_display_name', 200);
            $table->string('original_language', 10);
            $table->text('original_text');
            $table->string('translated_language', 10)->nullable();
            $table->text('translated_text')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();

            $table->index(['meeting_id', 'sequence'], 'meeting_transcripts_meeting_sequence_idx');
            $table->index(['meeting_id', 'started_at'], 'meeting_transcripts_meeting_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_transcripts');
    }
};

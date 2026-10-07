<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_ai_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->string('language', 10);
            $table->string('status', 20)->default('generating');
            $table->text('content');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 100)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'language'], 'meeting_ai_summaries_meeting_language_idx');
            $table->index(['meeting_id', 'status'], 'meeting_ai_summaries_meeting_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_ai_summaries');
    }
};

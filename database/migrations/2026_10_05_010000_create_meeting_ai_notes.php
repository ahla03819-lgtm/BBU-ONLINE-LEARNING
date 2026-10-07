<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_ai_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained()->restrictOnDelete();
            $table->string('language', 10);
            $table->text('content');
            $table->string('status', 20)->default('generating');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 100)->nullable();
            $table->json('provider_metadata')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'status'], 'meeting_ai_notes_meeting_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_ai_notes');
    }
};

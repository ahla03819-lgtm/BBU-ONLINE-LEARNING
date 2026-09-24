<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_series', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('school_class_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_subject_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->string('recurrence_type', 30);
            $table->json('weekdays')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->time('local_start_time');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('timezone', 64);
            $table->unsignedSmallInteger('max_participants')->default(50);
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('lifecycle_version')->default(0);
            $table->foreignId('split_from_series_id')->nullable()->constrained('meeting_series')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_class_id', 'status', 'starts_on', 'ends_on'], 'meeting_series_class_range_idx');
            $table->index(['class_subject_id', 'status'], 'meeting_series_subject_status_idx');
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->foreignId('meeting_series_id')->nullable()->after('id')->constrained('meeting_series')->restrictOnDelete();
            $table->date('series_occurrence_on')->nullable()->after('meeting_series_id');
            $table->unsignedBigInteger('series_sync_version')->nullable()->after('series_occurrence_on');
            $table->timestamp('series_override_at')->nullable()->after('series_sync_version');

            $table->unique(['meeting_series_id', 'series_occurrence_on'], 'meetings_series_occurrence_unique');
            $table->index(['meeting_series_id', 'status', 'series_override_at'], 'meetings_series_sync_idx');
            $table->index(['status', 'scheduled_start_at', 'scheduled_end_at'], 'meetings_calendar_range_idx');
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropIndex('meetings_calendar_range_idx');
            $table->dropIndex('meetings_series_sync_idx');
            $table->dropUnique('meetings_series_occurrence_unique');
            $table->dropConstrainedForeignId('meeting_series_id');
            $table->dropColumn(['series_occurrence_on', 'series_sync_version', 'series_override_at']);
        });

        Schema::dropIfExists('meeting_series');
    }
};

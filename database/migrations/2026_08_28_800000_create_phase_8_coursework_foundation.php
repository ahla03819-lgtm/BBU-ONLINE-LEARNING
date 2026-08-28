<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->longText('instructions')->nullable();
            $table->decimal('max_points', 8, 2)->unsigned();
            $table->timestamp('due_at')->nullable();
            $table->boolean('allow_resubmission')->default(true);
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('archived_from_status', 20)->nullable();
            $table->unsignedInteger('lifecycle_version')->default(0);
            $table->timestamps();
            $table->index(['class_subject_id', 'status', 'due_at'], 'assignments_subject_status_due_idx');
            $table->index(['class_subject_id', 'published_at', 'id'], 'assignments_subject_published_idx');
            $table->index(['created_by', 'status']);
        });

        Schema::create('assignment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('latest_revision_number')->default(0);
            $table->timestamp('last_submitted_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['assignment_id', 'student_profile_id'], 'assignment_submissions_student_unique');
            $table->index(['student_profile_id', 'status', 'updated_at'], 'assignment_submissions_student_status_idx');
            $table->index(['assignment_id', 'status', 'last_submitted_at'], 'assignment_submissions_assignment_status_idx');
        });

        Schema::create('assignment_submission_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_submission_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->uuid('client_uuid');
            $table->foreignId('authored_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->unsignedTinyInteger('draft_slot')->nullable()->default(1);
            $table->longText('body')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();
            $table->unique(['assignment_submission_id', 'revision_number'], 'submission_revisions_number_unique');
            $table->unique(['assignment_submission_id', 'client_uuid'], 'submission_revisions_client_unique');
            $table->unique(['assignment_submission_id', 'draft_slot'], 'submission_revisions_one_draft');
            $table->index(['assignment_submission_id', 'status', 'revision_number'], 'submission_revisions_status_idx');
            $table->index(['submitted_at', 'is_late']);
        });

        Schema::create('assignment_submission_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('assignment_submission_revision_id');
            $table->foreign('assignment_submission_revision_id', 'submission_attachments_revision_fk')->references('id')->on('assignment_submission_revisions')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->uuid('client_uuid');
            $table->string('disk', 50);
            $table->string('path', 700);
            $table->string('original_name', 180);
            $table->string('extension', 20);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedTinyInteger('position');
            $table->timestamps();
            $table->unique(['assignment_submission_revision_id', 'client_uuid'], 'submission_attachments_client_unique');
            $table->unique(['assignment_submission_revision_id', 'position'], 'submission_attachments_position_unique');
            $table->unique(['disk', 'path'], 'submission_attachments_storage_unique');
            $table->index(['assignment_submission_revision_id', 'id'], 'submission_attachments_revision_idx');
            $table->index(['uploaded_by', 'created_at']);
            $table->index('sha256');
        });

        Schema::create('assignment_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_submission_revision_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->decimal('points_awarded', 8, 2)->unsigned();
            $table->decimal('max_points_snapshot', 8, 2)->unsigned();
            $table->longText('feedback')->nullable();
            $table->string('change_reason', 500)->nullable();
            $table->foreignId('graded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['assignment_submission_id', 'revision_number'], 'assignment_grades_revision_unique');
            $table->index(['assignment_submission_revision_id', 'created_at'], 'assignment_grades_submission_revision_idx');
            $table->index(['graded_by', 'created_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE assignments ADD CONSTRAINT assignments_status_check CHECK (status IN ('draft','published','closed','archived')), ADD CONSTRAINT assignments_points_check CHECK (max_points > 0)");
            DB::statement("ALTER TABLE assignment_submissions ADD CONSTRAINT assignment_submissions_status_check CHECK (status IN ('draft','submitted'))");
            DB::statement("ALTER TABLE assignment_submission_revisions ADD CONSTRAINT submission_revisions_status_check CHECK (status IN ('draft','submitted')), ADD CONSTRAINT submission_revisions_state_check CHECK ((status = 'draft' AND draft_slot = 1 AND submitted_at IS NULL AND is_late IS NULL) OR (status = 'submitted' AND draft_slot IS NULL AND submitted_at IS NOT NULL AND is_late IS NOT NULL))");
            DB::statement('ALTER TABLE assignment_grades ADD CONSTRAINT assignment_grades_points_check CHECK (points_awarded >= 0 AND max_points_snapshot > 0 AND points_awarded <= max_points_snapshot)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_grades');
        Schema::dropIfExists('assignment_submission_attachments');
        Schema::dropIfExists('assignment_submission_revisions');
        Schema::dropIfExists('assignment_submissions');
        Schema::dropIfExists('assignments');
    }
};

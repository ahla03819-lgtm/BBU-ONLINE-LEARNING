<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('planned')->index();
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('grade_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sequence')->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150)->unique();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('grade_level_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('section', 30)->nullable();
            $table->string('status', 20)->default('planned')->index();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->timestamps();
            $table->unique(['academic_year_id', 'name', 'section']);
        });

        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['school_class_id', 'subject_id']);
        });

        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('employee_number', 50)->unique();
            $table->string('phone', 30)->nullable();
            $table->date('hired_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->string('student_number', 50)->unique();
            $table->date('date_of_birth')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained()->restrictOnDelete();
            $table->date('enrolled_on');
            $table->date('ended_on')->nullable();
            $table->string('end_reason')->nullable();
            $table->unsignedTinyInteger('current_slot')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['student_profile_id', 'academic_year_id', 'current_slot'], 'enrollments_one_current_per_year');
            $table->index(['school_class_id', 'current_slot']);
        });

        Schema::create('teacher_class_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('school_class_id')->constrained()->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('current_slot')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['school_class_id', 'current_slot'], 'teacher_classes_one_current_teacher');
            $table->index(['teacher_profile_id', 'current_slot'], 'teacher_class_current_idx');
        });

        Schema::create('teacher_class_subject_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_subject_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedTinyInteger('current_slot')->nullable()->default(1);
            $table->timestamps();
            $table->unique(['class_subject_id', 'current_slot'], 'class_subjects_one_current_teacher');
            $table->index(['teacher_profile_id', 'current_slot'], 'teacher_subject_current_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_class_subject_assignments');
        Schema::dropIfExists('teacher_class_assignments');
        Schema::dropIfExists('enrollments');
        Schema::dropIfExists('student_profiles');
        Schema::dropIfExists('teacher_profiles');
        Schema::dropIfExists('class_subjects');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('grade_levels');
        Schema::dropIfExists('academic_years');
    }
};

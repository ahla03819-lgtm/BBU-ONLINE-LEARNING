<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_registers', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('school_class_id')->constrained()->restrictOnDelete();
            $table->date('attendance_date');
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('roster_snapshot_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['school_class_id', 'attendance_date'], 'attendance_registers_class_date_unique');
            $table->index(['school_class_id', 'status', 'attendance_date'], 'attendance_registers_class_status_date_idx');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('present')->index();
            $table->string('reason', 1000)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();

            $table->unique(['attendance_register_id', 'student_profile_id'], 'attendance_records_register_student_unique');
            $table->index(['student_profile_id', 'created_at'], 'attendance_records_student_created_idx');
            $table->index(['enrollment_id', 'attendance_register_id'], 'attendance_records_enrollment_register_idx');
        });

        Schema::create('attendance_record_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_record_id')->constrained()->restrictOnDelete();
            $table->string('previous_status', 20);
            $table->string('previous_reason', 1000)->nullable();
            $table->string('new_status', 20);
            $table->string('new_reason', 1000)->nullable();
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('correction_reason', 1000);
            $table->timestamp('corrected_at');
            $table->timestamps();

            $table->index(['attendance_record_id', 'corrected_at'], 'attendance_revisions_record_corrected_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_record_revisions');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_registers');
    }
};

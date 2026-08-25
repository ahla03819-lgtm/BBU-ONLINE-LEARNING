<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('subject_id');
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->index(['school_class_id', 'status'], 'class_subjects_class_status_idx');
        });

        Schema::table('teacher_class_subject_assignments', function (Blueprint $table) {
            $table->dropForeign(['class_subject_id']);
            $table->foreign('class_subject_id')->references('id')->on('class_subjects')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_class_subject_assignments', function (Blueprint $table) {
            $table->dropForeign(['class_subject_id']);
            $table->foreign('class_subject_id')->references('id')->on('class_subjects')->cascadeOnDelete();
        });

        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropIndex('class_subjects_class_status_idx');
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['status', 'archived_at']);
        });
    }
};

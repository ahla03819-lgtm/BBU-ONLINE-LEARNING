<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Preserve historical enrollments while allowing a student to belong to
     * multiple active class workspaces in the same academic year.
     */
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique(['student_profile_id', 'school_class_id', 'current_slot'], 'enrollments_one_current_per_class');
            $table->dropUnique('enrollments_one_current_per_year');
        });
    }

    public function down(): void
    {
        if (DB::table('enrollments')
            ->where('current_slot', 1)
            ->select(['student_profile_id', 'academic_year_id'])
            ->groupBy(['student_profile_id', 'academic_year_id'])
            ->havingRaw('COUNT(*) > 1')
            ->exists()) {
            throw new RuntimeException('Cannot restore the legacy one-current-class-per-year constraint while students have multiple current class memberships in an academic year. No enrollment data or indexes were changed.');
        }

        $expectedIndex = collect(Schema::getIndexes('enrollments'))->contains(
            fn (array $index) => $index['name'] === 'enrollments_one_current_per_class'
                && $index['unique']
                && $index['columns'] === ['student_profile_id', 'school_class_id', 'current_slot'],
        );
        if (! $expectedIndex) {
            throw new LogicException('Cannot restore the legacy enrollment constraint because the expected per-class unique index is not present. No indexes were changed.');
        }

        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique(['student_profile_id', 'academic_year_id', 'current_slot'], 'enrollments_one_current_per_year');
            $table->dropUnique('enrollments_one_current_per_class');
        });
    }
};

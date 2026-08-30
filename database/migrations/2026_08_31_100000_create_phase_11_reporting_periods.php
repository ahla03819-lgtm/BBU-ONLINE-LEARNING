<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('reporting_periods')->restrictOnDelete();
            $table->string('name', 100);
            $table->string('code', 100);
            $table->unsignedSmallInteger('sequence');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('draft')->index();
            $table->timestamps();
            $table->unique(['academic_year_id', 'code']);
            $table->index(['academic_year_id', 'parent_id', 'sequence']);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('reporting_period_id')->nullable()->after('class_subject_id')->constrained()->nullOnDelete()->index();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reporting_period_id');
        });
        Schema::dropIfExists('reporting_periods');
    }
};

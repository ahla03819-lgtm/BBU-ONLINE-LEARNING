<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('join_code', 7)->nullable()->unique();
            $table->boolean('join_code_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropUnique(['join_code']);
            $table->dropColumn(['join_code', 'join_code_enabled']);
        });
    }
};

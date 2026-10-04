<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('school_classes', 'cover_image_path')) {
            Schema::table('school_classes', fn (Blueprint $table) => $table->string('cover_image_path')->nullable()->after('join_code_enabled'));
        }
        if (! Schema::hasColumn('channels', 'image_path')) {
            Schema::table('channels', fn (Blueprint $table) => $table->string('image_path')->nullable()->after('description'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('channels', 'image_path')) Schema::table('channels', fn (Blueprint $table) => $table->dropColumn('image_path'));
        if (Schema::hasColumn('school_classes', 'cover_image_path')) Schema::table('school_classes', fn (Blueprint $table) => $table->dropColumn('cover_image_path'));
    }
};

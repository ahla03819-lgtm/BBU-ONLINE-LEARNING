<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('conversation_messages')->whereNull('body')->exists()) {
            throw new RuntimeException('Rollback cannot restore a required conversation message body while attachment-only messages exist.');
        }

        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->text('body')->nullable(false)->change();
        });
    }
};

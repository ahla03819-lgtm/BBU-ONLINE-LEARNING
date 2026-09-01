<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('conversation_call_participants')) {
            Schema::create('conversation_call_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_call_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->timestamp('invited_at')->nullable();
                $table->timestamp('joined_at')->nullable();
                $table->timestamp('left_at')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->timestamps();
                $table->unique(['conversation_call_id', 'user_id'], 'ccp_call_user_unique');
                $table->index(['user_id', 'left_at'], 'ccp_user_left_index');
            });

            return;
        }

        // MySQL may retain this new table when an earlier index DDL statement fails.
        // This branch completes only that incomplete, unapplied migration.
        Schema::table('conversation_call_participants', function (Blueprint $table) {
            $table->unique(['conversation_call_id', 'user_id'], 'ccp_call_user_unique');
            $table->index(['user_id', 'left_at'], 'ccp_user_left_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_call_participants');
    }
};

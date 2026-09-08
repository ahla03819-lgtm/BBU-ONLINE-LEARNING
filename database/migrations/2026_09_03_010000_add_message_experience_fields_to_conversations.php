<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_message_id')->nullable()->after('sender_user_id')->constrained('conversation_messages')->nullOnDelete();
            $table->timestamp('deleted_at')->nullable()->after('updated_at');
            $table->index(['conversation_id', 'reply_to_message_id']);
        });

        Schema::create('conversation_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();
            $table->unique(['conversation_message_id', 'user_id', 'emoji'], 'conversation_message_reactions_unique');
        });

        Schema::create('conversation_message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['conversation_message_id', 'user_id'], 'conversation_message_reads_unique');
        });

        Schema::create('conversation_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pinned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique('conversation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_pins');
        Schema::dropIfExists('conversation_message_reads');
        Schema::dropIfExists('conversation_message_reactions');
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'reply_to_message_id']);
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropColumn('deleted_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('body')->nullable()->change();
            $table->unsignedBigInteger('reactions_version')->default(0)->after('hidden_reason');
        });

        Schema::create('message_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('client_uuid');
            $table->string('disk', 50);
            // Keep the composite unique key within MySQL's 3072-byte utf8mb4 limit.
            $table->string('path', 700);
            $table->string('original_name', 180);
            $table->string('extension', 20);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedTinyInteger('position');
            $table->timestamps();

            $table->unique(['message_id', 'client_uuid']);
            $table->unique(['message_id', 'position']);
            $table->unique(['disk', 'path'], 'message_attachments_storage_unique');
            $table->index(['message_id', 'id']);
        });

        Schema::create('message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 32);
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->index(['message_id', 'reaction']);
            $table->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
        Schema::dropIfExists('message_attachments');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('reactions_version');
            $table->text('body')->nullable(false)->change();
        });
    }
};

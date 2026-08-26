<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->uuid('public_uuid')->nullable()->after('id');
        });
        DB::table('meeting_participants')->orderBy('id')->eachById(fn ($participant) => DB::table('meeting_participants')->where('id', $participant->id)->update(['public_uuid' => (string) Str::uuid()]));
        Schema::table('meeting_participants', fn (Blueprint $table) => $table->unique('public_uuid', 'meeting_participants_public_uuid_unique'));
    }

    public function down(): void
    {
        Schema::table('meeting_participants', fn (Blueprint $table) => $table->dropUnique('meeting_participants_public_uuid_unique'));
        Schema::table('meeting_participants', fn (Blueprint $table) => $table->dropColumn('public_uuid'));
    }
};

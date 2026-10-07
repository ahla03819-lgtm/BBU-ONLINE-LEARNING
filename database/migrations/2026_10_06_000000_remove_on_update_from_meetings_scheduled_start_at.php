<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            'ALTER TABLE `meetings` MODIFY `scheduled_start_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        // Technical rollback only: this deliberately restores the legacy
        // behavior that can corrupt a scheduled start during unrelated writes.
        DB::statement(
            'ALTER TABLE `meetings` MODIFY `scheduled_start_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
    }
};

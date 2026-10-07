<?php

namespace Tests\Feature;

use App\Models\Meeting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MeetingScheduledStartSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_mysql_column_has_no_automatic_update_clause(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL information_schema is required to verify the ON UPDATE clause.');
        }

        $column = DB::selectOne(<<<'SQL'
            SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = 'meetings'
              AND column_name = 'scheduled_start_at'
            SQL);

        $this->assertNotNull($column);
        $this->assertSame('timestamp', strtolower($column->DATA_TYPE));
        $this->assertSame('timestamp', strtolower($column->COLUMN_TYPE));
        $this->assertSame('NO', $column->IS_NULLABLE);
        $this->assertContains(strtolower((string) $column->COLUMN_DEFAULT), [
            'current_timestamp',
            'current_timestamp()',
        ]);
        $this->assertStringNotContainsString('on update', strtolower((string) $column->EXTRA));
    }

    public function test_updating_an_unrelated_field_preserves_scheduled_start_at(): void
    {
        $meeting = Meeting::factory()->create([
            'title' => 'Original title',
            'scheduled_start_at' => '2026-10-10 06:00:00',
        ]);

        $before = DB::table('meetings')
            ->where('id', $meeting->id)
            ->value('scheduled_start_at');

        $meeting->update(['title' => 'Updated title']);

        $after = DB::table('meetings')
            ->where('id', $meeting->id)
            ->value('scheduled_start_at');

        $this->assertSame((string) $before, (string) $after);
    }
}

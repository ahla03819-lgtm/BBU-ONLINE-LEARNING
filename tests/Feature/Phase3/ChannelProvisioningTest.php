<?php

namespace Tests\Feature\Phase3;

use App\Actions\Collaboration\CreateSchoolClass;
use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Enums\ChannelType;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_class_creation_transactionally_provisions_default_channels(): void
    {
        $class = app(CreateSchoolClass::class)->handle(['academic_year_id' => AcademicYear::factory()->create()->id, 'grade_level_id' => GradeLevel::factory()->create()->id, 'name' => 'Seven A', 'section' => 'A', 'status' => 'planned']);
        $this->assertDatabaseHas('channels', ['school_class_id' => $class->id, 'type' => 'general', 'default_slot' => 1]);
        $this->assertDatabaseHas('channels', ['school_class_id' => $class->id, 'type' => 'announcement', 'default_slot' => 2]);
    }

    public function test_default_provisioning_is_idempotent(): void
    {
        $class = SchoolClass::factory()->create();
        $action = app(ProvisionDefaultChannels::class);
        $action->handle($class);
        $action->handle($class);
        $this->assertSame(2, $class->channels()->count());
    }

    public function test_database_rejects_duplicate_default_slot_and_slug(): void
    {
        $class = SchoolClass::factory()->create();
        Channel::factory()->create(['school_class_id' => $class, 'name' => 'General', 'slug' => 'general', 'type' => ChannelType::General, 'default_slot' => 1]);
        $this->expectException(UniqueConstraintViolationException::class);
        Channel::factory()->create(['school_class_id' => $class, 'slug' => 'another', 'default_slot' => 1]);
    }

    public function test_backfill_is_dry_run_safe_and_repeatable(): void
    {
        $class = SchoolClass::factory()->create();
        $this->artisan('collaboration:backfill-channels --dry-run')->assertSuccessful();
        $this->assertSame(0, $class->channels()->count());
        $this->artisan('collaboration:backfill-channels')->assertSuccessful();
        $this->artisan('collaboration:backfill-channels')->assertSuccessful();
        $this->assertSame(2, $class->channels()->count());
    }
}

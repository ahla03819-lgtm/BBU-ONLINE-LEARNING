<?php

namespace Tests\Feature\Phase4;

use App\Actions\Collaboration\ProvisionDefaultChannels;
use App\Actions\People\EnrollStudent;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\CollaborationAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_presence_authorization_is_current_and_payload_is_safe(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $class = SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'grade_level_id' => GradeLevel::factory(), 'status' => 'active']);
        app(ProvisionDefaultChannels::class)->handle($class);
        $channel = $class->channels()->first();
        $user = User::factory()->create(['name' => 'Safe Student', 'email' => 'private@example.test']);
        $user->assignRole('Student');
        app(EnrollStudent::class)->handle(StudentProfile::factory()->create(['user_id' => $user]), $class, '2026-09-01');
        $this->assertTrue($user->can('messages.view'));
        $this->assertTrue(app(CollaborationAccess::class)->canAccessChannel($user, $channel));
        $this->actingAs($user)->post('/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'presence-collaboration.channel.'.$channel->id])->assertOk();
        $channels = app(BroadcastManager::class)->driver()->getChannels();
        $payload = $channels['collaboration.channel.{channelId}']($user, $channel->id);
        $this->assertSame(['id' => $user->id, 'name' => 'Safe Student', 'participant_type' => 'student'], $payload);
        $this->assertArrayNotHasKey('email', $payload);
        $this->assertArrayNotHasKey('permissions', $payload);

        $outsider = User::factory()->create();
        $outsider->assignRole('Student');
        $this->assertFalse($channels['collaboration.channel.{channelId}']($outsider, $channel->id));
    }
}

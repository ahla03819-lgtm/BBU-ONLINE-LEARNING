<?php

namespace Tests\Feature\Phase9;

use App\Enums\ChannelType;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Channel;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_inbox_is_owned_newest_first_and_paginated(): void
    {
        $owner = $this->student();
        $other = $this->student();
        $notifications = collect(range(1, 17))->map(fn (int $index) => UserNotification::factory()->create([
            'user_id' => $owner,
            'deduplication_key' => 'owner-'.$index,
            'created_at' => now()->subMinutes(18 - $index),
            'updated_at' => now()->subMinutes(18 - $index),
        ]));
        UserNotification::factory()->create(['user_id' => $other, 'context' => ['title' => 'Private other-user title']]);

        $this->actingAs($owner)->get(route('notifications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->has('notifications.data', 15)
            ->where('notifications.data.0.public_id', $notifications->last()->public_id)
            ->where('notifications.current_page', 1));
        $this->actingAs($owner)->get(route('notifications.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
            ->has('notifications.data', 2)
            ->where('notifications.current_page', 2));
    }

    public function test_mark_one_is_idempotent_and_another_users_uuid_is_not_exposed(): void
    {
        $owner = $this->student();
        $other = $this->student();
        $notification = UserNotification::factory()->create(['user_id' => $owner]);

        $this->actingAs($other)->patch(route('notifications.read', $notification->public_id))->assertNotFound();
        $this->assertNull($notification->refresh()->read_at);

        $this->actingAs($owner)->patch(route('notifications.read', $notification->public_id))->assertRedirect();
        $firstReadAt = $notification->refresh()->read_at;
        $this->actingAs($owner)->patch(route('notifications.read', $notification->public_id))->assertRedirect();
        $this->assertTrue($firstReadAt->equalTo($notification->refresh()->read_at));
    }

    public function test_mark_all_and_shared_unread_count_are_recipient_scoped_and_idempotent(): void
    {
        $owner = $this->student();
        $other = $this->student();
        UserNotification::factory()->count(2)->create(['user_id' => $owner]);
        UserNotification::factory()->read()->create(['user_id' => $owner]);
        $otherNotification = UserNotification::factory()->create(['user_id' => $other]);

        $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('notificationInbox.unread_count', 2));
        $this->actingAs($owner)->patch(route('notifications.read-all'))->assertRedirect();
        $this->actingAs($owner)->patch(route('notifications.read-all'))->assertRedirect();
        $this->actingAs($owner)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notificationInbox.unread_count', 0)
            ->where('notifications.data.0.is_read', true)
            ->where('notifications.data.1.is_read', true)
            ->where('notifications.data.2.is_read', true));
        $this->assertNull($otherNotification->refresh()->read_at);
    }

    public function test_authorized_internal_link_is_relative_and_context_is_allowlisted(): void
    {
        [$student, $class] = $this->enrolledStudent();
        $channel = Channel::factory()->create(['school_class_id' => $class, 'type' => ChannelType::Announcement, 'default_slot' => 2]);
        UserNotification::factory()->create([
            'user_id' => $student,
            'type' => 'announcement.published',
            'context' => ['title' => 'Class update', 'body' => 'Must not be exposed'],
            'route_name' => 'collaboration.channels.show',
            'route_parameters' => ['schoolClass' => $class->id, 'channel' => $channel->id],
        ]);

        $this->actingAs($student)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.href', "/collaboration/classes/{$class->id}/channels/{$channel->id}")
            ->where('notifications.data.0.context', ['title' => 'Class update'])
            ->missing('notifications.data.0.context.body'));
    }

    public function test_revoked_access_suppresses_link_and_resource_context(): void
    {
        [$student, $class, $enrollment] = $this->enrolledStudent();
        $channel = Channel::factory()->create(['school_class_id' => $class, 'type' => ChannelType::Announcement, 'default_slot' => 2]);
        UserNotification::factory()->create([
            'user_id' => $student,
            'type' => 'announcement.published',
            'context' => ['title' => 'No longer visible'],
            'route_name' => 'collaboration.channels.show',
            'route_parameters' => ['schoolClass' => $class->id, 'channel' => $channel->id],
        ]);
        $enrollment->update(['current_slot' => null, 'ended_on' => now()]);

        $this->actingAs($student)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.href', null)
            ->where('notifications.data.0.context', [])
            ->missing('notifications.data.0.route_name')
            ->missing('notifications.data.0.route_parameters'));
    }

    public function test_participant_removal_stays_sanitized_and_linkless(): void
    {
        $student = $this->student();
        UserNotification::factory()->create([
            'user_id' => $student,
            'type' => 'meeting.participant-removed',
            'context' => ['message' => 'You were removed from a meeting.', 'livekit_identity' => 'private-provider-id'],
            'route_name' => null,
            'route_parameters' => null,
        ]);

        $this->actingAs($student)->get(route('notifications.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notifications.data.0.href', null)
            ->where('notifications.data.0.context', ['message' => 'You were removed from a meeting.'])
            ->missing('notifications.data.0.context.livekit_identity'));
    }

    public function test_guest_unverified_inactive_and_permissionless_users_are_denied(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));

        $unverified = User::factory()->unverified()->create();
        $unverified->assignRole('Student');
        $this->actingAs($unverified)->get(route('notifications.index'))->assertRedirect(route('verification.notice'));

        $inactive = User::factory()->create(['status' => 'inactive']);
        $inactive->assignRole('Student');
        $this->actingAs($inactive)->get(route('notifications.index'))->assertRedirect(route('login'));

        $permissionless = User::factory()->create();
        $this->actingAs($permissionless)->get(route('notifications.index'))->assertForbidden();
    }

    public function test_super_admin_cannot_mark_another_users_notification_read(): void
    {
        $owner = $this->student();
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');
        $notification = UserNotification::factory()->create(['user_id' => $owner]);

        $this->actingAs($superAdmin)->patch(route('notifications.read', $notification->public_id))->assertNotFound();
        $this->assertNull($notification->refresh()->read_at);
    }

    public function test_inertia_page_and_navigation_contract_are_present(): void
    {
        $student = $this->student();
        $this->actingAs($student)->get(route('notifications.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('notifications.view'))
            ->has('notificationInbox.unread_count'));
        $this->assertFileExists(resource_path('js/Pages/Notifications/Index.jsx'));
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
        $this->assertStringContainsString("permissions.includes('notifications.view')", $layout);
        $this->assertStringContainsString("notificationUrl={canViewNotifications ? '/notifications' : null}", $layout);
        $topbar = file_get_contents(resource_path('js/Components/UI/AppTopbar.jsx'));
        $this->assertStringContainsString('href={notificationUrl}', $topbar);
    }

    private function student(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Student');

        return $user;
    }

    /** @return array{User, SchoolClass, Enrollment} */
    private function enrolledStudent(): array
    {
        $year = AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $student = $this->student();
        $profile = StudentProfile::factory()->create(['user_id' => $student]);
        $enrollment = Enrollment::factory()->create([
            'student_profile_id' => $profile,
            'academic_year_id' => $year,
            'school_class_id' => $class,
            'current_slot' => 1,
        ]);

        return [$student->refresh(), $class, $enrollment];
    }
}

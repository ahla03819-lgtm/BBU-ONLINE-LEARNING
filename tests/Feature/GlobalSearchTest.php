<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Channel;
use App\Models\ClassSubject;
use App\Models\Conversation;
use App\Models\ConversationMember;
use App\Models\ConversationMessage;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_is_denied_and_query_validation_is_bounded(): void
    {
        $this->getJson(route('global-search', ['q' => 'private']))->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('global-search', ['q' => 'x']))->assertUnprocessable();
        $this->actingAs($user)->getJson(route('global-search', ['q' => 'valid', 'category' => 'unknown']))->assertUnprocessable();
        $this->actingAs($user)->getJson(route('global-search', ['q' => 'valid', 'limit' => 26]))->assertUnprocessable();
    }

    public function test_account_gates_redirect_inactive_unverified_and_password_change_accounts(): void
    {
        $inactive = User::factory()->create(['status' => AccountStatus::Inactive]);
        $this->actingAs($inactive)->get(route('global-search', ['q' => 'valid']))->assertRedirect(route('login'));
        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->get(route('global-search', ['q' => 'valid']))->assertRedirect(route('verification.notice'));
        $passwordChange = User::factory()->create(['must_change_password' => true]);
        $this->actingAs($passwordChange)->get(route('global-search', ['q' => 'valid']))->assertRedirect(route('password.initialize.show'));
    }

    public function test_private_messages_and_files_are_visible_only_to_active_members_without_paths(): void
    {
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $outsider = User::factory()->create();
        $superAdmin = tap(User::factory()->create(), fn (User $user) => $user->assignRole('Super Admin'));
        $conversation = Conversation::factory()->direct()->create(['created_by_user_id' => $member->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $member->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $otherMember->id]);
        $message = ConversationMessage::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $otherMember->id, 'body' => 'Private needle message']);
        $message->attachments()->create(['disk' => 'local', 'path' => 'conversation-attachments/secret/private-file.pdf', 'original_name' => 'needle-file.pdf', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 7, 'attachment_type' => 'document']);
        $hidden = ConversationMessage::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $otherMember->id, 'body' => 'Private hidden']);
        $hidden->update(['body' => null, 'deleted_at' => now()]);

        $response = $this->actingAs($member)->getJson(route('global-search', ['q' => 'needle']))->assertOk();
        $response->assertJsonPath('results.messages.0.title', $otherMember->name)->assertJsonPath('results.files.0.title', 'needle-file.pdf');
        $this->assertStringNotContainsString('conversation-attachments/', json_encode($response->json()));
        $this->assertFalse(collect($response->json('results.messages'))->pluck('description')->contains('Private hidden'));
        $this->actingAs($outsider)->getJson(route('global-search', ['q' => 'needle']))->assertOk()->assertJsonCount(0, 'results.messages')->assertJsonCount(0, 'results.files');
        $this->actingAs($superAdmin)->getJson(route('global-search', ['q' => 'needle']))->assertOk()->assertJsonCount(0, 'results.messages')->assertJsonCount(0, 'results.files');
    }

    public function test_people_classes_meetings_assignments_and_announcements_are_relationship_scoped(): void
    {
        [$teacher, $student, $class, $subject] = $this->academicMembers();
        $outsider = User::factory()->create(['name' => 'Hidden Person']);
        $otherClass = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active, 'name' => 'Hidden Class']);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'title' => 'Authorized Meeting', 'host_user_id' => $teacher->id, 'created_by' => $teacher->id]);
        Meeting::factory()->create(['school_class_id' => $otherClass->id, 'title' => 'Hidden Meeting']);
        $published = Assignment::factory()->published()->create(['class_subject_id' => $subject->id, 'created_by' => $teacher->id, 'title' => 'Authorized Assignment']);
        Assignment::factory()->create(['class_subject_id' => $subject->id, 'created_by' => $teacher->id, 'title' => 'Hidden Draft Assignment']);
        $channel = Channel::factory()->create(['school_class_id' => $class->id]);
        Announcement::factory()->published()->create(['channel_id' => $channel->id, 'author_id' => $teacher->id, 'title' => 'Authorized Announcement']);
        Announcement::factory()->create(['channel_id' => $channel->id, 'author_id' => $teacher->id, 'title' => 'Hidden Draft Announcement']);
        $people = $this->actingAs($student)->getJson(route('global-search', ['q' => $teacher->name, 'category' => 'people']))->assertOk()->json('results.people');
        $this->assertTrue(collect($people)->pluck('title')->contains($teacher->name));
        $this->assertStringNotContainsString($teacher->email, json_encode($people));
        $this->actingAs($student)->getJson(route('global-search', ['q' => 'Hidden', 'category' => 'people']))->assertOk()->assertJsonCount(0, 'results.people');
        $this->actingAs($student)->getJson(route('global-search', ['q' => 'Authorized Class', 'category' => 'classes']))->assertOk()->assertJsonPath('results.classes.0.title', 'Authorized Class A');
        $this->actingAs($student)->getJson(route('global-search', ['q' => 'Hidden Class', 'category' => 'classes']))->assertOk()->assertJsonCount(0, 'results.classes');
        $this->actingAs($teacher)->getJson(route('global-search', ['q' => 'Authorized Meeting', 'category' => 'meetings']))->assertOk()->assertJsonPath('results.meetings.0.title', $meeting->title);
        $this->actingAs($teacher)->getJson(route('global-search', ['q' => 'Hidden Meeting', 'category' => 'meetings']))->assertOk()->assertJsonCount(0, 'results.meetings');
        $meetingPayload = $this->actingAs($teacher)->getJson(route('global-search', ['q' => 'Authorized Meeting', 'category' => 'meetings']))->json();
        $this->assertStringNotContainsString('livekit', strtolower(json_encode($meetingPayload)));
        $assignmentPayload = $this->actingAs($student)->getJson(route('global-search', ['q' => 'Authorized Assignment', 'category' => 'assignments']))->assertOk()->assertJsonPath('results.assignments.0.title', $published->title)->json();
        $this->assertStringNotContainsString('grade', strtolower(json_encode($assignmentPayload)));
        $this->actingAs($student)->getJson(route('global-search', ['q' => 'Hidden Draft Assignment', 'category' => 'assignments']))->assertOk()->assertJsonCount(0, 'results.assignments');
        $this->actingAs($student)->getJson(route('global-search', ['q' => 'Authorized Announcement', 'category' => 'announcements']))->assertOk()->assertJsonPath('results.announcements.0.title', 'Authorized Announcement');
        $this->actingAs($student)->getJson(route('global-search', ['q' => 'Hidden Draft Announcement', 'category' => 'announcements']))->assertOk()->assertJsonCount(0, 'results.announcements');
    }

    public function test_result_limits_are_enforced_for_all_and_single_categories(): void
    {
        [$teacher] = $this->academicMembers();
        $conversation = Conversation::factory()->direct()->create(['created_by_user_id' => $teacher->id]);
        ConversationMember::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $teacher->id]);
        foreach (range(1, 27) as $index) {
            ConversationMessage::factory()->create(['conversation_id' => $conversation->id, 'sender_user_id' => $teacher->id, 'body' => "Limit needle {$index}"]);
        }

        $this->actingAs($teacher)->getJson(route('global-search', ['q' => 'Limit needle']))->assertOk()->assertJsonCount(5, 'results.messages');
        $this->actingAs($teacher)->getJson(route('global-search', ['q' => 'Limit needle', 'category' => 'messages', 'limit' => 25]))->assertOk()->assertJsonCount(25, 'results.messages');
    }

    /** @return array{0: User, 1: User, 2: SchoolClass, 3: ClassSubject} */
    private function academicMembers(): array
    {
        $class = SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active, 'name' => 'Authorized Class', 'section' => 'A']);
        $teacher = tap(User::factory()->create(['name' => 'Authorized Teacher']), fn (User $user) => $user->assignRole('Teacher'));
        $teacherProfile = TeacherProfile::factory()->create(['user_id' => $teacher->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $teacherProfile->id, 'school_class_id' => $class->id, 'current_slot' => 1, 'starts_on' => now()->subDay()->toDateString()]);
        $student = tap(User::factory()->create(['name' => 'Authorized Student']), fn (User $user) => $user->assignRole('Student'));
        $studentProfile = StudentProfile::factory()->create(['user_id' => $student->id]);
        Enrollment::factory()->create(['student_profile_id' => $studentProfile->id, 'school_class_id' => $class->id, 'academic_year_id' => $class->academic_year_id, 'current_slot' => 1, 'enrolled_on' => now()->subDay()->toDateString()]);

        return [$teacher, $student, $class, ClassSubject::factory()->create(['school_class_id' => $class->id])];
    }
}

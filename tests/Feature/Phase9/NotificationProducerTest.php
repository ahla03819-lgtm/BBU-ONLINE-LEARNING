<?php

namespace Tests\Feature\Phase9;

use App\Actions\Collaboration\PublishAnnouncement;
use App\Enums\ChannelType;
use App\Enums\SchoolClassStatus;
use App\Events\AnnouncementPublished;
use App\Events\AssignmentGraded;
use App\Events\AssignmentLifecycleChanged;
use App\Events\AssignmentPublished;
use App\Events\AssignmentSubmitted;
use App\Events\MeetingCancelled;
use App\Events\MeetingParticipantRemoved;
use App\Events\MeetingScheduled;
use App\Events\MeetingStarted;
use App\Events\MessageSent;
use App\Listeners\Notifications\CreateAnnouncementPublishedNotifications;
use App\Listeners\Notifications\CreateAssignmentGradedNotification;
use App\Listeners\Notifications\CreateAssignmentPublishedNotifications;
use App\Listeners\Notifications\CreateAssignmentSubmittedNotifications;
use App\Listeners\Notifications\CreateMeetingCancelledNotifications;
use App\Listeners\Notifications\CreateMeetingParticipantRemovedNotification;
use App\Listeners\Notifications\CreateMeetingScheduledNotifications;
use App\Listeners\Notifications\CreateMeetingStartedNotifications;
use App\Models\AcademicYear;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AssignmentGrade;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionRevision;
use App\Models\Channel;
use App\Models\ClassSubject;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\Message;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherClassSubjectAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\NotificationRecipientResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class NotificationProducerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_announcement_recipients_use_only_current_authorized_relationships(): void
    {
        [$class, $subject] = $this->activeSubject();
        $student = $this->student($class);
        $historicalStudent = $this->student($class, current: false);
        $inactiveStudent = $this->student($class, active: false);
        $unverifiedStudent = $this->student($class, verified: false);
        $subjectTeacher = $this->teacher($subject);
        $classTeacher = $this->teacher($subject, classTeacher: true);
        $historicalTeacher = $this->teacher($subject, current: false);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $author = User::factory()->create();
        $author->assignRole('Super Admin');
        $channel = Channel::factory()->create([
            'school_class_id' => $class,
            'type' => ChannelType::Announcement,
            'default_slot' => 2,
            'created_by' => $author,
        ]);
        $announcement = Announcement::factory()->published()->create(['channel_id' => $channel, 'author_id' => $author]);

        $recipients = app(NotificationRecipientResolver::class)->forAnnouncement($announcement)->modelKeys();

        $this->assertEqualsCanonicalizing([$student->id, $subjectTeacher->id, $classTeacher->id], $recipients);
        $this->assertNotContains($historicalStudent->id, $recipients);
        $this->assertNotContains($inactiveStudent->id, $recipients);
        $this->assertNotContains($unverifiedStudent->id, $recipients);
        $this->assertNotContains($historicalTeacher->id, $recipients);
        $this->assertNotContains($admin->id, $recipients);
        $this->assertNotContains($author->id, $recipients);
    }

    public function test_announcement_publish_dispatches_event_and_producer_is_retry_safe(): void
    {
        [$class] = $this->activeSubject();
        $student = $this->student($class);
        $author = User::factory()->create();
        $author->assignRole('Admin');
        $channel = Channel::factory()->create(['school_class_id' => $class, 'type' => ChannelType::Announcement, 'default_slot' => 2]);
        $announcement = Announcement::factory()->create(['channel_id' => $channel, 'author_id' => $author]);

        Event::fakeFor(function () use ($announcement) {
            app(PublishAnnouncement::class)->handle($announcement);
            Event::assertDispatched(AnnouncementPublished::class, fn ($event) => $event->announcement->is($announcement));
        }, [AnnouncementPublished::class]);

        $announcement->refresh();
        $listener = app(CreateAnnouncementPublishedNotifications::class);
        $listener->handle(new AnnouncementPublished($announcement));
        $listener->handle(new AnnouncementPublished($announcement));

        $notification = UserNotification::query()->sole();
        $this->assertSame($student->id, $notification->user_id);
        $this->assertSame('announcement.published', $notification->type);
        $this->assertSame('collaboration.channels.show', $notification->route_name);
        $this->assertSame(['schoolClass' => $class->id, 'channel' => $channel->id], $notification->route_parameters);
    }

    public function test_meeting_schedule_start_and_cancel_producers_use_safe_current_scope(): void
    {
        [$class, $subject] = $this->activeSubject();
        $student = $this->student($class);
        $historicalStudent = $this->student($class, current: false);
        $teacher = $this->teacher($subject);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $meeting = Meeting::factory()->create([
            'school_class_id' => $class,
            'class_subject_id' => $subject,
            'created_by' => $teacher,
            'host_user_id' => $teacher,
            'lifecycle_version' => 4,
        ]);

        app(CreateMeetingScheduledNotifications::class)->handle(new MeetingScheduled($meeting));
        app(CreateMeetingStartedNotifications::class)->handle(new MeetingStarted($meeting));
        app(CreateMeetingCancelledNotifications::class)->handle(new MeetingCancelled($meeting));

        foreach ([$student, $teacher] as $recipient) {
            $this->assertEqualsCanonicalizing(
                ['meeting.scheduled', 'meeting.started', 'meeting.cancelled'],
                UserNotification::query()->where('user_id', $recipient->id)->pluck('type')->all(),
            );
        }
        $this->assertFalse(UserNotification::query()->where('user_id', $historicalStudent->id)->exists());
        $this->assertFalse(UserNotification::query()->where('user_id', $admin->id)->exists());
        $this->assertTrue(UserNotification::query()->where('route_name', 'meetings.show')->get()->every(fn ($notification) => $notification->route_parameters === ['schoolClass' => $class->id, 'meeting' => $meeting->uuid]));
    }

    public function test_removed_participant_notification_is_sanitized_and_has_no_link(): void
    {
        [$class, $subject] = $this->activeSubject();
        $student = $this->student($class);
        $teacher = $this->teacher($subject);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class, 'class_subject_id' => $subject, 'created_by' => $teacher, 'host_user_id' => $teacher]);
        $participant = MeetingParticipant::factory()->create([
            'meeting_id' => $meeting,
            'user_id' => $student,
            'removed_at' => now(),
            'removed_by' => $teacher,
            'removal_reason' => 'Private moderation reason',
        ]);

        app(CreateMeetingParticipantRemovedNotification::class)->handle(new MeetingParticipantRemoved($participant));

        $notification = UserNotification::query()->sole();
        $serialized = json_encode($notification->toArray());
        $this->assertSame($student->id, $notification->user_id);
        $this->assertSame(['message' => 'You were removed from a meeting.'], $notification->context);
        $this->assertNull($notification->route_name);
        $this->assertNull($notification->route_parameters);
        $this->assertStringNotContainsString($participant->livekit_identity, $serialized);
        $this->assertStringNotContainsString('Private moderation reason', $serialized);
    }

    public function test_assignment_publish_uses_one_canonical_producer_and_current_students_only(): void
    {
        [$class, $subject] = $this->activeSubject();
        $student = $this->student($class);
        $historical = $this->student($class, current: false);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject, 'lifecycle_version' => 3]);
        $listener = app(CreateAssignmentPublishedNotifications::class);

        $listener->handle(new AssignmentPublished($assignment));
        $listener->handle(new AssignmentPublished($assignment));
        AssignmentLifecycleChanged::dispatch($assignment, 'publish');

        $notification = UserNotification::query()->sole();
        $this->assertSame($student->id, $notification->user_id);
        $this->assertSame('assignment.published', $notification->type);
        $this->assertFalse(UserNotification::query()->where('user_id', $historical->id)->exists());
        $this->assertSame('coursework.assignments.show', $notification->route_name);
        $this->assertSame(['schoolClass' => $class->id, 'classSubject' => $subject->id, 'assignment' => $assignment->id], $notification->route_parameters);
    }

    public function test_submission_notifies_current_subject_teacher_without_private_work(): void
    {
        [$class, $subject] = $this->activeSubject();
        $student = $this->student($class);
        $teacher = $this->teacher($subject);
        $historicalTeacher = $this->teacher($subject, current: false);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject]);
        $submission = AssignmentSubmission::factory()->create(['assignment_id' => $assignment, 'student_profile_id' => $student->studentProfile]);
        $revision = AssignmentSubmissionRevision::factory()->create([
            'assignment_submission_id' => $submission,
            'authored_by' => $student,
            'status' => 'submitted',
            'draft_slot' => null,
            'body' => 'Private submitted answer',
            'submitted_at' => now(),
            'is_late' => false,
        ]);

        app(CreateAssignmentSubmittedNotifications::class)->handle(new AssignmentSubmitted($revision));

        $notification = UserNotification::query()->sole();
        $this->assertSame($teacher->id, $notification->user_id);
        $this->assertFalse(UserNotification::query()->where('user_id', $historicalTeacher->id)->exists());
        $this->assertStringNotContainsString('Private submitted answer', json_encode($notification->toArray()));
    }

    public function test_grade_notifies_only_current_submission_owner_without_grade_value(): void
    {
        [$class, $subject] = $this->activeSubject();
        $student = $this->student($class);
        $other = $this->student($class);
        $teacher = $this->teacher($subject);
        $assignment = Assignment::factory()->published()->create(['class_subject_id' => $subject]);
        $submission = AssignmentSubmission::factory()->create(['assignment_id' => $assignment, 'student_profile_id' => $student->studentProfile]);
        $revision = AssignmentSubmissionRevision::factory()->create(['assignment_submission_id' => $submission, 'authored_by' => $student]);
        $grade = AssignmentGrade::factory()->create([
            'assignment_submission_id' => $submission,
            'assignment_submission_revision_id' => $revision,
            'points_awarded' => 97,
            'graded_by' => $teacher,
        ]);

        app(CreateAssignmentGradedNotification::class)->handle(new AssignmentGraded($grade));

        $notification = UserNotification::query()->sole();
        $this->assertSame($student->id, $notification->user_id);
        $this->assertFalse(UserNotification::query()->where('user_id', $other->id)->exists());
        $this->assertArrayNotHasKey('points_awarded', $notification->context);
        $this->assertStringNotContainsString('97', json_encode($notification->context));
    }

    public function test_producers_are_queued_after_commit_and_messages_have_no_notification_producer(): void
    {
        foreach ([
            CreateAnnouncementPublishedNotifications::class,
            CreateMeetingScheduledNotifications::class,
            CreateMeetingStartedNotifications::class,
            CreateMeetingCancelledNotifications::class,
            CreateMeetingParticipantRemovedNotification::class,
            CreateAssignmentPublishedNotifications::class,
            CreateAssignmentSubmittedNotifications::class,
            CreateAssignmentGradedNotification::class,
        ] as $listenerClass) {
            $listener = app($listenerClass);
            $this->assertInstanceOf(ShouldQueue::class, $listener);
            $this->assertTrue($listener->afterCommit);
            $this->assertSame(3, $listener->tries);
        }

        MessageSent::dispatch(Message::factory()->create());
        $this->assertDatabaseCount('user_notifications', 0);
    }

    /** @return array{SchoolClass, ClassSubject} */
    private function activeSubject(): array
    {
        $year = AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $year, 'status' => SchoolClassStatus::Active]);
        $subject = ClassSubject::factory()->create(['school_class_id' => $class]);

        return [$class, $subject];
    }

    private function student(SchoolClass $class, bool $current = true, bool $active = true, bool $verified = true): User
    {
        $user = User::factory()->create([
            'status' => $active ? 'active' : 'inactive',
            'email_verified_at' => $verified ? now() : null,
        ]);
        $user->assignRole('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user]);
        Enrollment::factory()->create([
            'student_profile_id' => $profile,
            'academic_year_id' => $class->academic_year_id,
            'school_class_id' => $class,
            'current_slot' => $current ? 1 : null,
            'ended_on' => $current ? null : now()->toDateString(),
        ]);

        return $user->refresh();
    }

    private function teacher(ClassSubject $subject, bool $current = true, bool $classTeacher = false): User
    {
        $user = User::factory()->create();
        $user->assignRole('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user]);
        if ($classTeacher) {
            TeacherClassAssignment::factory()->create([
                'teacher_profile_id' => $profile,
                'school_class_id' => $subject->school_class_id,
                'current_slot' => $current ? 1 : null,
                'ends_on' => $current ? null : now()->toDateString(),
            ]);
        } else {
            TeacherClassSubjectAssignment::factory()->create([
                'teacher_profile_id' => $profile,
                'class_subject_id' => $subject,
                'current_slot' => $current ? 1 : null,
                'ends_on' => $current ? null : now()->toDateString(),
            ]);
        }

        return $user->refresh();
    }
}

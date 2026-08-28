<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\ClassSubjectStatus;
use App\Enums\SchoolClassStatus;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\AssignmentGrade;
use App\Models\AssignmentSubmissionRevision;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class NotificationRecipientResolver
{
    /** @return Collection<int, User> */
    public function forAnnouncement(Announcement $announcement): Collection
    {
        $announcement->loadMissing(['channel.schoolClass.academicYear', 'channel.classSubject']);
        $channel = $announcement->channel;
        if (! $channel || $channel->status !== ChannelStatus::Active || ! $this->isActiveClass($channel->schoolClass)) {
            return new Collection;
        }

        return $this->eligibleUsers()
            ->where(function (Builder $users) use ($channel) {
                $users->where(function (Builder $students) use ($channel) {
                    $students->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Student'))
                        ->whereHas('studentProfile.enrollments', fn (Builder $enrollments) => $this->currentEnrollment($enrollments, $channel->schoolClass));
                })->orWhere(function (Builder $teachers) use ($channel) {
                    $teachers->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Teacher'))
                        ->where(function (Builder $relationships) use ($channel) {
                            $relationships->whereHas('teacherProfile.classAssignments', fn (Builder $assignments) => $assignments
                                ->where('school_class_id', $channel->school_class_id)
                                ->where('current_slot', 1));

                            if ($channel->type === ChannelType::Subject && $channel->class_subject_id) {
                                $relationships->orWhereHas('teacherProfile.classSubjectAssignments', fn (Builder $assignments) => $assignments
                                    ->where('class_subject_id', $channel->class_subject_id)
                                    ->where('current_slot', 1));
                            } elseif (in_array($channel->type, [ChannelType::General, ChannelType::Announcement], true)) {
                                $relationships->orWhereHas('teacherProfile.classSubjectAssignments', fn (Builder $assignments) => $assignments
                                    ->where('current_slot', 1)
                                    ->whereHas('classSubject', fn (Builder $subjects) => $subjects
                                        ->where('school_class_id', $channel->school_class_id)
                                        ->where('status', ClassSubjectStatus::Active->value)));
                            }
                        });
                });
            })
            ->whereKeyNot($announcement->author_id)
            ->get();
    }

    /** @return Collection<int, User> */
    public function forMeeting(Meeting $meeting): Collection
    {
        $meeting->loadMissing(['schoolClass.academicYear', 'classSubject']);
        if (! $this->isActiveClass($meeting->schoolClass) || ($meeting->classSubject && $meeting->classSubject->status !== ClassSubjectStatus::Active)) {
            return new Collection;
        }

        return $this->eligibleUsers()
            ->where(function (Builder $users) use ($meeting) {
                $users->where(function (Builder $students) use ($meeting) {
                    $students->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Student'))
                        ->whereHas('studentProfile.enrollments', fn (Builder $enrollments) => $this->currentEnrollment($enrollments, $meeting->schoolClass));
                })->orWhere(function (Builder $teachers) use ($meeting) {
                    $teachers->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Teacher'))
                        ->where(function (Builder $relationships) use ($meeting) {
                            $relationships->whereHas('teacherProfile.classAssignments', fn (Builder $assignments) => $assignments
                                ->where('school_class_id', $meeting->school_class_id)
                                ->where('current_slot', 1));
                            if ($meeting->class_subject_id) {
                                $relationships->orWhereHas('teacherProfile.classSubjectAssignments', fn (Builder $assignments) => $assignments
                                    ->where('class_subject_id', $meeting->class_subject_id)
                                    ->where('current_slot', 1));
                            }
                        });
                })->orWhere('users.id', $meeting->host_user_id);
            })
            ->whereDoesntHave('meetingParticipations', fn (Builder $participants) => $participants
                ->where('meeting_id', $meeting->id)
                ->whereNotNull('removed_at'))
            ->get();
    }

    /** @return Collection<int, User> */
    public function forRemovedParticipant(MeetingParticipant $participant): Collection
    {
        return $this->eligibleUsers()->whereKey($participant->user_id)->get();
    }

    /** @return Collection<int, User> */
    public function forPublishedAssignment(Assignment $assignment): Collection
    {
        $assignment->loadMissing('classSubject.schoolClass.academicYear');
        $subject = $assignment->classSubject;
        if (! $this->isActiveSubject($subject)) {
            return new Collection;
        }

        return $this->eligibleUsers()
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Student'))
            ->whereHas('studentProfile.enrollments', fn (Builder $enrollments) => $this->currentEnrollment($enrollments, $subject->schoolClass))
            ->get();
    }

    /** @return Collection<int, User> */
    public function forSubmittedAssignment(AssignmentSubmissionRevision $revision): Collection
    {
        $revision->loadMissing('submission.assignment.classSubject.schoolClass.academicYear');
        $subject = $revision->submission->assignment->classSubject;
        if (! $this->isActiveSubject($subject)) {
            return new Collection;
        }

        return $this->eligibleUsers()
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Teacher'))
            ->whereHas('teacherProfile.classSubjectAssignments', fn (Builder $assignments) => $assignments
                ->where('class_subject_id', $subject->id)
                ->where('current_slot', 1))
            ->get();
    }

    /** @return Collection<int, User> */
    public function forGradedAssignment(AssignmentGrade $grade): Collection
    {
        $grade->loadMissing('submission.studentProfile', 'submission.assignment.classSubject.schoolClass.academicYear');
        $submission = $grade->submission;
        $subject = $submission->assignment->classSubject;
        if (! $this->isActiveSubject($subject)) {
            return new Collection;
        }

        return $this->eligibleUsers()
            ->whereKey($submission->studentProfile->user_id)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', 'Student'))
            ->whereHas('studentProfile.enrollments', fn (Builder $enrollments) => $this->currentEnrollment($enrollments, $subject->schoolClass))
            ->get();
    }

    private function eligibleUsers(): Builder
    {
        return User::query()->where('status', 'active')->whereNotNull('email_verified_at');
    }

    private function currentEnrollment(Builder $query, SchoolClass $class): Builder
    {
        return $query->where('school_class_id', $class->id)
            ->where('academic_year_id', $class->academic_year_id)
            ->where('current_slot', 1);
    }

    private function isActiveSubject(ClassSubject $subject): bool
    {
        return $subject->status === ClassSubjectStatus::Active && $this->isActiveClass($subject->schoolClass);
    }

    private function isActiveClass(SchoolClass $class): bool
    {
        $class->loadMissing('academicYear');

        return $class->status === SchoolClassStatus::Active
            && $class->academicYear->status === AcademicYearStatus::Active;
    }
}

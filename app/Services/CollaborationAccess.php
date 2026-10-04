<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use App\Enums\ClassSubjectStatus;
use App\Enums\SchoolClassStatus;
use App\Models\Channel;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class CollaborationAccess
{
    public function classesFor(User $user): Builder
    {
        $query = SchoolClass::query();
        if ($this->isAdministrator($user)) {
            return $query;
        }

        $this->applyActiveAcademicScope($query);
        if ($user->hasRole('Teacher')) {
            return $query->where(function (Builder $classes) use ($user) {
                $classes->whereHas('teacherAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id)))
                    ->orWhereHas('classSubjects', fn (Builder $subjects) => $subjects->where('status', ClassSubjectStatus::Active->value)->whereHas('teacherAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))));
            });
        }

        return $query->whereHas('enrollments', fn (Builder $enrollments) => $enrollments->where('current_slot', 1)->whereHas('studentProfile', fn (Builder $students) => $students->where('user_id', $user->id)));
    }

    public function channelsFor(User $user): Builder
    {
        $query = Channel::query()->whereHas('schoolClass', fn (Builder $classes) => $classes->whereIn('id', $this->classesFor($user)->select('id')));
        if ($this->isAdministrator($user)) {
            return $query;
        }

        $query->where('status', ChannelStatus::Active->value);
        if ($user->hasRole('Teacher')) {
            return $query->where(function (Builder $channels) use ($user) {
                $channels->whereHas('schoolClass.teacherAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id)))
                    ->orWhereIn('type', [ChannelType::General->value, ChannelType::Announcement->value])
                    ->orWhereHas('classSubject', fn (Builder $subjects) => $subjects->where('status', ClassSubjectStatus::Active->value)->whereHas('teacherAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))));
            });
        }

        return $query;
    }

    public function canAccessClass(User $user, SchoolClass $class): bool
    {
        return $this->classesFor($user)->whereKey($class)->exists();
    }

    public function canAccessChannel(User $user, Channel $channel): bool
    {
        return $this->channelsFor($user)->whereKey($channel)->exists();
    }

    public function isCurrentClassTeacher(User $user, SchoolClass $class): bool
    {
        return $this->isAcademicallyActive($class) && $class->teacherAssignments()->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))->exists();
    }

    public function isCurrentSubjectTeacher(User $user, ClassSubject $subject): bool
    {
        return $subject->status === ClassSubjectStatus::Active && $this->isAcademicallyActive($subject->schoolClass) && $subject->teacherAssignments()->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $teachers) => $teachers->where('user_id', $user->id))->exists();
    }

    public function canManageCustomChannel(User $user, SchoolClass $class): bool
    {
        return $this->isAdministrator($user) || $this->isCurrentClassTeacher($user, $class);
    }

    public function canAuthorIn(User $user, Channel $channel): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }
        if ($this->isCurrentClassTeacher($user, $channel->schoolClass)) {
            return true;
        }

        return $channel->type === ChannelType::Subject && $channel->classSubject && $this->isCurrentSubjectTeacher($user, $channel->classSubject);
    }

    public function isAdministrator(User $user): bool
    {
        return $user->hasAnyRole(['Super Admin', 'Admin']);
    }

    public function isAcademicallyActive(SchoolClass $class): bool
    {
        $class->loadMissing('academicYear');

        return $class->status === SchoolClassStatus::Active && $class->academicYear->status === AcademicYearStatus::Active;
    }

    private function applyActiveAcademicScope(Builder $query): void
    {
        $query->where('status', SchoolClassStatus::Active->value)->whereHas('academicYear', fn (Builder $years) => $years->where('status', AcademicYearStatus::Active->value));
    }
}

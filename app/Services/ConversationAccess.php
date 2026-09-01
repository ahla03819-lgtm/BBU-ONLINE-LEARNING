<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ConversationAccess
{
    public function hasSharedCurrentClass(User $actor, User $candidate): bool
    {
        if ($actor->is($candidate) || ! $candidate->isActive() || ! $candidate->hasVerifiedEmail()) {
            return false;
        }

        return $this->classesForUser($actor)
            ->whereIn('school_classes.id', $this->classesForUser($candidate)->select('school_classes.id'))
            ->exists();
    }

    public function isCurrentClassMember(User $user, SchoolClass $schoolClass): bool
    {
        return $this->classesForUser($user)->whereKey($schoolClass->id)->exists();
    }

    public function eligibleUsers(User $actor): Builder
    {
        $classIds = $this->classesForUser($actor)->select('school_classes.id');

        return User::query()
            ->whereKeyNot($actor->id)
            ->where('status', 'active')
            ->whereNotNull('email_verified_at')
            ->where(function (Builder $users) use ($classIds) {
                $users->whereHas('studentProfile.enrollments', fn (Builder $enrollments) => $enrollments->where('current_slot', 1)->whereIn('school_class_id', $classIds))
                    ->orWhereHas('teacherProfile.classAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereIn('school_class_id', $classIds))
                    ->orWhereHas('teacherProfile.classSubjectAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('classSubject', fn (Builder $subject) => $subject->whereIn('school_class_id', $classIds)));
            });
    }

    public function conversationsFor(User $user): Builder
    {
        return Conversation::query()
            ->whereHas('members', fn (Builder $members) => $members->where('user_id', $user->id)->whereNull('left_at'));
    }

    private function classesForUser(User $user): Builder
    {
        return SchoolClass::query()->where(function (Builder $classes) use ($user) {
            $classes->whereHas('enrollments', fn (Builder $enrollments) => $enrollments->where('current_slot', 1)->whereHas('studentProfile', fn (Builder $profile) => $profile->where('user_id', $user->id)))
                ->orWhereHas('teacherAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $profile) => $profile->where('user_id', $user->id)))
                ->orWhereHas('classSubjects.teacherAssignments', fn (Builder $assignments) => $assignments->where('current_slot', 1)->whereHas('teacherProfile', fn (Builder $profile) => $profile->where('user_id', $user->id)));
        });
    }
}

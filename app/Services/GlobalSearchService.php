<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageAttachment;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Support\Collection;

class GlobalSearchService
{
    public function __construct(
        private readonly CollaborationAccess $collaboration,
        private readonly MeetingAccess $meetingAccess,
    ) {}

    /** @return array<string, Collection<int, array<string, mixed>>> */
    public function search(User $user, string $term, string $category, int $limit): array
    {
        $categories = $category === 'all' ? ['people', 'messages', 'files', 'classes', 'meetings', 'assignments', 'announcements'] : [$category];
        $results = [];

        foreach ($categories as $name) {
            $results[$name] = $this->{$name}($user, $term, $limit);
        }

        return $results;
    }

    private function people(User $user, string $term, int $limit): Collection
    {
        if (! ($user->can('students.view') || $user->can('teachers.view'))) {
            return collect();
        }

        $classIds = $this->collaboration->classesFor($user)->select('school_classes.id');

        return User::query()
            ->where(fn ($query) => $query->whereKey($user)->orWhereHas('teacherProfile.classAssignments', fn ($assignments) => $assignments->whereIn('school_class_id', $classIds))->orWhereHas('studentProfile.enrollments', fn ($enrollments) => $enrollments->whereIn('school_class_id', $classIds)))
            ->where('name', 'like', "%{$term}%")
            ->orderBy('name')->limit($limit)->get(['id', 'name', 'avatar_path'])
            ->map(fn (User $person) => $this->item('people', $person->name, null, $person->avatarUrl(), route('people.index', ['search' => $person->name])));
    }

    private function messages(User $user, string $term, int $limit): Collection
    {
        return ConversationMessage::query()->whereNull('deleted_at')->where('body', 'like', "%{$term}%")
            ->whereHas('conversation.members', fn ($members) => $members->where('user_id', $user->id)->whereNull('left_at'))
            ->with(['conversation:id,public_uuid,name,type', 'sender:id,name'])->latest('id')->limit($limit)->get()
            ->map(fn (ConversationMessage $message) => $this->item('messages', $message->sender->name, $this->snippet($message->body), null, route('conversations.show', $message->conversation), ['conversation' => $message->conversation->name ?: 'Private conversation']));
    }

    private function files(User $user, string $term, int $limit): Collection
    {
        return ConversationMessageAttachment::query()->where('original_name', 'like', "%{$term}%")
            ->whereHas('message.conversation.members', fn ($members) => $members->where('user_id', $user->id)->whereNull('left_at'))
            ->with('message.conversation:id,public_uuid,name,type')->latest('id')->limit($limit)->get()
            ->map(fn (ConversationMessageAttachment $attachment) => $this->item('files', $attachment->original_name, strtoupper($attachment->extension).' file', null, route('conversations.show', $attachment->message->conversation), ['conversation' => $attachment->message->conversation->name ?: 'Private conversation']));
    }

    private function classes(User $user, string $term, int $limit): Collection
    {
        if (! $user->can('classes.view')) {
            return collect();
        }

        return $this->collaboration->classesFor($user)->where(fn ($query) => $query->where('name', 'like', "%{$term}%")->orWhere('section', 'like', "%{$term}%"))
            ->with(['academicYear:id,name', 'gradeLevel:id,name'])->orderBy('name')->limit($limit)->get()
            ->map(fn (SchoolClass $class) => $this->item('classes', trim("{$class->name} {$class->section}"), collect([$class->gradeLevel?->name, $class->academicYear?->name])->filter()->join(' · '), null, route('classes.show', $class)));
    }

    private function meetings(User $user, string $term, int $limit): Collection
    {
        if (! $user->can('meetings.view')) {
            return collect();
        }

        return $this->meetingAccess->meetingsFor($user)->where('title', 'like', "%{$term}%")->with(['schoolClass:id,name,section,status,academic_year_id', 'schoolClass.academicYear:id,status'])->latest('scheduled_start_at')->limit($limit)->get()
            ->filter(fn (Meeting $meeting) => $user->can('view', $meeting))
            ->map(fn (Meeting $meeting) => $this->item('meetings', $meeting->title, trim("{$meeting->schoolClass->name} {$meeting->schoolClass->section}").' · '.($meeting->scheduled_start_at?->format('M j, Y g:i A') ?? ucfirst($meeting->status->value)), null, route('meetings.show', [$meeting->schoolClass, $meeting])));
    }

    private function assignments(User $user, string $term, int $limit): Collection
    {
        if (! $user->can('assignments.view')) {
            return collect();
        }

        return Assignment::query()->where('title', 'like', "%{$term}%")->with('classSubject.schoolClass:id,name,section')->latest()->limit($limit * 3)->get()
            ->filter(fn (Assignment $assignment) => $user->can('view', $assignment))->take($limit)
            ->map(function (Assignment $assignment) {
                $class = $assignment->classSubject->schoolClass;

                return $this->item('assignments', $assignment->title, trim("{$class->name} {$class->section}").($assignment->due_at ? ' · Due '.$assignment->due_at->format('M j') : ''), null, route('coursework.assignments.show', [$class, $assignment->classSubject, $assignment]));
            });
    }

    private function announcements(User $user, string $term, int $limit): Collection
    {
        if (! $user->can('announcements.view')) {
            return collect();
        }

        $channelIds = $this->collaboration->channelsFor($user)->select('channels.id');

        return Announcement::query()->whereIn('channel_id', $channelIds)->where('title', 'like', "%{$term}%")
            ->with('channel.schoolClass:id,name,section')->latest('published_at')->limit($limit * 3)->get()
            ->filter(fn (Announcement $announcement) => $user->can('view', $announcement))->take($limit)
            ->map(fn (Announcement $announcement) => $this->item('announcements', $announcement->title, trim("{$announcement->channel->schoolClass->name} {$announcement->channel->schoolClass->section}"), null, route('collaboration.channels.show', [$announcement->channel->schoolClass, $announcement->channel])));
    }

    /** @return array<string, mixed> */
    private function item(string $type, string $title, ?string $description, ?string $avatarUrl, string $url, array $meta = []): array
    {
        return compact('type', 'title', 'description', 'avatarUrl', 'url', 'meta');
    }

    private function snippet(?string $body): string
    {
        return str($body ?? '')->squish()->limit(130)->toString();
    }
}

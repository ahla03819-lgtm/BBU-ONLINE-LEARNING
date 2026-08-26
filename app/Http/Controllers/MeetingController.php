<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\CancelMeeting;
use App\Actions\Meetings\CreateMeeting;
use App\Actions\Meetings\UpdateMeeting;
use App\Enums\ClassSubjectStatus;
use App\Http\Requests\Meetings\CancelMeetingRequest;
use App\Http\Requests\Meetings\CreateMeetingRequest;
use App\Http\Requests\Meetings\UpdateMeetingRequest;
use App\Models\ClassSubject;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Services\MeetingAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class MeetingController extends Controller
{
    public function index(SchoolClass $schoolClass, MeetingAccess $access): Response
    {
        abort_unless($access->canAccessClass(auth()->user(), $schoolClass), 403);
        $meetings = $access->meetingsFor(auth()->user())
            ->where('school_class_id', $schoolClass->id)
            ->with(['classSubject.subject:id,code,name', 'host:id,name'])
            ->orderByDesc('scheduled_start_at')
            ->get()
            ->map(fn (Meeting $meeting) => [...$this->meetingData($meeting),
                'can_update' => auth()->user()->can('update', $meeting),
                'can_cancel' => auth()->user()->can('cancel', $meeting),
            ]);

        return Inertia::render('Meetings/Index', [
            'schoolClass' => $this->classData($schoolClass),
            'meetings' => $meetings,
            'canCreate' => $this->subjectsFor($schoolClass, $access)->isNotEmpty() || auth()->user()->can('create', [Meeting::class, $schoolClass, null]),
        ]);
    }

    public function show(SchoolClass $schoolClass, Meeting $meeting, MeetingAccess $access): Response
    {
        $this->ensureVisible($schoolClass, $meeting, $access);

        return Inertia::render('Meetings/Show', [
            'schoolClass' => $this->classData($schoolClass),
            'meeting' => [...$this->meetingData($meeting->load(['classSubject.subject:id,code,name', 'host:id,name'])),
                'can_update' => auth()->user()->can('update', $meeting),
                'can_cancel' => auth()->user()->can('cancel', $meeting),
            ],
        ]);
    }

    public function create(SchoolClass $schoolClass, MeetingAccess $access): Response
    {
        abort_unless($access->canAccessClass(auth()->user(), $schoolClass), 403);
        $subjects = $this->subjectsFor($schoolClass, $access);
        $canCreateGeneral = auth()->user()->can('create', [Meeting::class, $schoolClass, null]);
        abort_unless($canCreateGeneral || $subjects->isNotEmpty(), 403);

        return Inertia::render('Meetings/Create', $this->formData($schoolClass, $subjects, $access, null, $canCreateGeneral));
    }

    public function store(CreateMeetingRequest $request, SchoolClass $schoolClass, CreateMeeting $action): RedirectResponse
    {
        $meeting = $action->handle($request->user(), $schoolClass, $request->validated());

        return redirect()->route('meetings.show', [$schoolClass, $meeting])->with('success', 'Meeting scheduled.');
    }

    public function edit(SchoolClass $schoolClass, Meeting $meeting, MeetingAccess $access): Response
    {
        $this->ensureVisible($schoolClass, $meeting, $access);
        $this->authorize('update', $meeting);
        $subjects = $this->subjectsFor($schoolClass, $access);

        return Inertia::render('Meetings/Edit', [
            ...$this->formData($schoolClass, $subjects, $access, $meeting, auth()->user()->can('create', [Meeting::class, $schoolClass, null])),
            'meeting' => $this->meetingData($meeting->load(['classSubject.subject:id,code,name', 'host:id,name'])),
        ]);
    }

    public function update(UpdateMeetingRequest $request, SchoolClass $schoolClass, Meeting $meeting, UpdateMeeting $action): RedirectResponse
    {
        $action->handle($request->user(), $meeting, $request->validated());

        return redirect()->route('meetings.show', [$schoolClass, $meeting])->with('success', 'Meeting updated.');
    }

    public function cancel(CancelMeetingRequest $request, SchoolClass $schoolClass, Meeting $meeting, CancelMeeting $action): RedirectResponse
    {
        $action->handle($request->user(), $meeting);

        return back()->with('success', 'Meeting cancelled.');
    }

    private function ensureVisible(SchoolClass $schoolClass, Meeting $meeting, MeetingAccess $access): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        abort_unless($access->meetingsFor(auth()->user())->whereKey($meeting->id)->exists(), 403);
        $this->authorize('view', $meeting);
    }

    private function subjectsFor(SchoolClass $schoolClass, MeetingAccess $access): Collection
    {
        return $schoolClass->classSubjects()
            ->where('status', ClassSubjectStatus::Active->value)
            ->with('subject:id,code,name')
            ->orderBy('id')
            ->get()
            ->filter(fn (ClassSubject $subject) => $access->canCreateMeeting(auth()->user(), $schoolClass, $subject))
            ->values();
    }

    private function formData(SchoolClass $schoolClass, Collection $subjects, MeetingAccess $access, ?Meeting $meeting, bool $canCreateGeneral): array
    {
        $hostOptions = [];
        if ($access->isAdministrator(auth()->user())) {
            if ($canCreateGeneral) {
                $hostOptions['general'] = $access->eligibleHostsFor(auth()->user(), $schoolClass)->map->only('id', 'name')->values();
            }
            foreach ($subjects as $subject) {
                $hostOptions[(string) $subject->id] = $access->eligibleHostsFor(auth()->user(), $schoolClass, $subject)->map->only('id', 'name')->values();
            }
        }

        return [
            'schoolClass' => $this->classData($schoolClass),
            'subjects' => $subjects->map(fn (ClassSubject $subject) => ['id' => $subject->id, 'name' => $subject->subject->code.' — '.$subject->subject->name]),
            'hostOptions' => $hostOptions,
            'isAdministrator' => $access->isAdministrator(auth()->user()),
            'canCreateGeneral' => $canCreateGeneral,
            'capacity' => ['default' => config('meetings.default_max_participants'), 'min' => config('meetings.min_participants'), 'max' => config('meetings.max_participants')],
        ];
    }

    private function meetingData(Meeting $meeting): array
    {
        return [
            'uuid' => $meeting->uuid,
            'title' => $meeting->title,
            'description' => $meeting->description,
            'class_subject_id' => $meeting->class_subject_id,
            'class_subject' => $meeting->classSubject ? ['id' => $meeting->classSubject->id, 'name' => $meeting->classSubject->subject->code.' — '.$meeting->classSubject->subject->name] : null,
            'host_user_id' => $meeting->host_user_id,
            'host' => $meeting->host?->only('id', 'name'),
            'scheduled_start_at' => $meeting->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $meeting->scheduled_end_at?->toIso8601String(),
            'status' => $meeting->status->value,
            'max_participants' => $meeting->max_participants,
            'lifecycle_version' => $meeting->lifecycle_version,
        ];
    }

    private function classData(SchoolClass $schoolClass): array
    {
        $schoolClass->loadMissing(['academicYear:id,name,status', 'gradeLevel:id,name']);

        return [
            'id' => $schoolClass->id,
            'name' => $schoolClass->name,
            'section' => $schoolClass->section,
            'status' => $schoolClass->status->value,
            'academic_year' => $schoolClass->academicYear->only('id', 'name', 'status'),
            'grade_level' => $schoolClass->gradeLevel->only('id', 'name'),
        ];
    }
}

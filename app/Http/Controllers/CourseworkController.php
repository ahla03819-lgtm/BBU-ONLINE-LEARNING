<?php

namespace App\Http\Controllers;

use App\Actions\Coursework\CreateAssignment;
use App\Actions\Coursework\TransitionAssignment;
use App\Actions\Coursework\UpdateAssignment;
use App\Http\Requests\Coursework\CreateAssignmentRequest;
use App\Http\Requests\Coursework\TransitionAssignmentRequest;
use App\Http\Requests\Coursework\UpdateAssignmentRequest;
use App\Models\Assignment;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Services\CourseworkAccess;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CourseworkController extends Controller
{
    public function index(SchoolClass $schoolClass, CourseworkAccess $access): Response
    {
        abort_unless(auth()->user()->can('assignments.view') && $access->canAccessClass(auth()->user(), $schoolClass), 403);
        $assignments = Assignment::query()->whereHas('classSubject', fn ($q) => $q->where('school_class_id', $schoolClass->id))->with(['classSubject.subject:id,code,name', 'creator:id,name'])->latest()->get()->filter(fn ($a) => auth()->user()->can('view', $a))->map(fn ($a) => $this->assignmentData($a))->values();
        $subjects = $schoolClass->classSubjects()->with('subject:id,code,name')->get()->filter(fn ($s) => auth()->user()->can('create', [Assignment::class, $s]))->map(fn ($s) => ['id' => $s->id, 'name' => $s->subject->code.' — '.$s->subject->name])->values();

        $schoolClass->load('academicYear:id,name', 'gradeLevel:id,name');

        return Inertia::render('Coursework/Index', ['schoolClass' => ['id' => $schoolClass->id, 'name' => $schoolClass->name, 'section' => $schoolClass->section, 'academic_year' => $schoolClass->academicYear->only('id', 'name'), 'grade_level' => $schoolClass->gradeLevel->only('id', 'name')], 'assignments' => $assignments, 'subjects' => $subjects]);
    }

    public function create(SchoolClass $schoolClass, ClassSubject $classSubject): Response
    {
        $this->ensureSubject($schoolClass, $classSubject);
        $this->authorize('create', [Assignment::class, $classSubject]);

        return Inertia::render('Coursework/Assignments/Create', ['schoolClass' => $schoolClass->only('id', 'name', 'section'), 'classSubject' => $this->subjectData($classSubject)]);
    }

    public function store(CreateAssignmentRequest $request, SchoolClass $schoolClass, ClassSubject $classSubject, CreateAssignment $action): RedirectResponse
    {
        $this->ensureSubject($schoolClass, $classSubject);
        $assignment = $action->handle($request->user(), $classSubject, $request->validated());

        return redirect()->route('coursework.assignments.show', [$schoolClass, $classSubject, $assignment])->with('success', 'Assignment draft created.');
    }

    public function show(SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment): Response
    {
        $this->ensureAssignment($schoolClass, $classSubject, $assignment);
        $this->authorize('view', $assignment);
        $user = auth()->user();
        $submission = $user->studentProfile ? $assignment->submissions()->where('student_profile_id', $user->studentProfile->id)->with(['revisions.attachments', 'grades.grader:id,name'])->first() : null;
        $review = $user->can('submissions.review') ? $assignment->submissions()->with(['studentProfile.user:id,name', 'revisions.attachments', 'grades.grader:id,name'])->get()->filter(fn ($s) => $user->can('view', $s))->map(fn ($s) => $this->submissionData($s))->values() : [];

        return Inertia::render('Coursework/Assignments/Show', ['schoolClass' => $schoolClass->only('id', 'name', 'section'), 'classSubject' => $this->subjectData($classSubject), 'assignment' => $this->assignmentData($assignment), 'submission' => $submission ? $this->submissionData($submission) : null, 'reviewSubmissions' => $review]);
    }

    public function edit(SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment): Response
    {
        $this->ensureAssignment($schoolClass, $classSubject, $assignment);
        $this->authorize('update', $assignment);

        return Inertia::render('Coursework/Assignments/Edit', ['schoolClass' => $schoolClass->only('id', 'name', 'section'), 'classSubject' => $this->subjectData($classSubject), 'assignment' => $this->assignmentData($assignment)]);
    }

    public function update(UpdateAssignmentRequest $request, SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, UpdateAssignment $action): RedirectResponse
    {
        $this->ensureAssignment($schoolClass, $classSubject, $assignment);
        $action->handle($request->user(), $assignment, $request->validated());

        return redirect()->route('coursework.assignments.show', [$schoolClass, $classSubject, $assignment])->with('success', 'Assignment updated.');
    }

    public function transition(TransitionAssignmentRequest $request, SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, TransitionAssignment $action): RedirectResponse
    {
        $this->ensureAssignment($schoolClass, $classSubject, $assignment);
        $ability = (string) $request->route('courseworkAbility');
        $action->handle($request->user(), $assignment, $ability, (int) $request->validated('lifecycle_version'));

        return back()->with('success', 'Assignment lifecycle updated.');
    }

    private function ensureSubject(SchoolClass $class, ClassSubject $subject): void
    {
        abort_unless($subject->school_class_id === $class->id, 404);
    }

    private function ensureAssignment(SchoolClass $class, ClassSubject $subject, Assignment $assignment): void
    {
        $this->ensureSubject($class, $subject);
        abort_unless($assignment->class_subject_id === $subject->id, 404);
    }

    private function subjectData(ClassSubject $subject): array
    {
        $subject->loadMissing('subject:id,code,name');

        return ['id' => $subject->id, 'name' => $subject->subject->code.' — '.$subject->subject->name];
    }

    private function assignmentData(Assignment $a): array
    {
        $a->loadMissing('classSubject.subject:id,code,name');

        return ['id' => $a->id, 'title' => $a->title, 'instructions' => $a->instructions, 'max_points' => $a->max_points, 'due_at' => $a->due_at?->toIso8601String(), 'allow_resubmission' => $a->allow_resubmission, 'status' => $a->status->value, 'published_at' => $a->published_at?->toIso8601String(), 'closed_at' => $a->closed_at?->toIso8601String(), 'lifecycle_version' => $a->lifecycle_version, 'subject' => $this->subjectData($a->classSubject), 'can_update' => auth()->user()->can('update', $a), 'can_publish' => auth()->user()->can('publish', $a), 'can_close' => auth()->user()->can('close', $a), 'can_archive' => auth()->user()->can('archive', $a), 'can_restore' => auth()->user()->can('restore', $a), 'can_submit' => app(CourseworkAccess::class)->canSubmit(auth()->user(), $a)];
    }

    private function submissionData($s): array
    {
        $s->loadMissing(['studentProfile.user:id,name', 'revisions.attachments', 'grades.grader:id,name']);

        return ['id' => $s->id, 'status' => $s->status->value, 'student' => $s->studentProfile->user->only('id', 'name'), 'last_submitted_at' => $s->last_submitted_at?->toIso8601String(), 'revisions' => $s->revisions->sortByDesc('revision_number')->map(fn ($r) => ['id' => $r->id, 'revision_number' => $r->revision_number, 'status' => $r->status->value, 'body' => $r->body, 'submitted_at' => $r->submitted_at?->toIso8601String(), 'is_late' => $r->is_late, 'attachments' => $r->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name, 'size_bytes' => $a->size_bytes])])->values(), 'grades' => $s->grades->sortByDesc('revision_number')->map(fn ($g) => ['revision_number' => $g->revision_number, 'points_awarded' => $g->points_awarded, 'max_points' => $g->max_points_snapshot, 'feedback' => $g->feedback, 'change_reason' => $g->change_reason, 'grader' => $g->grader->only('name'), 'created_at' => $g->created_at?->toIso8601String()])->values(), 'can_review' => auth()->user()->can('review', $s)];
    }
}

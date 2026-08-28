<?php

namespace App\Http\Controllers;

use App\Actions\Coursework\SaveSubmissionDraft;
use App\Actions\Coursework\StartResubmission;
use App\Actions\Coursework\SubmitAssignment;
use App\Http\Requests\Coursework\SaveSubmissionDraftRequest;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;

class AssignmentSubmissionController extends Controller
{
    public function save(SaveSubmissionDraftRequest $request, SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, SaveSubmissionDraft $action): RedirectResponse
    {
        $this->ensureScope($schoolClass, $classSubject, $assignment);
        $action->handle($request->user(), $assignment, $request->safe()->except('attachments'), $request->file('attachments', []));

        return back()->with('success', 'Submission draft saved.');
    }

    public function submit(SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, AssignmentSubmission $submission, SubmitAssignment $action): RedirectResponse
    {
        $this->ensureSubmissionScope($schoolClass, $classSubject, $assignment, $submission);
        $action->handle(auth()->user(), $submission);

        return back()->with('success', 'Work submitted.');
    }

    public function resubmit(SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, AssignmentSubmission $submission, StartResubmission $action): RedirectResponse
    {
        $this->ensureSubmissionScope($schoolClass, $classSubject, $assignment, $submission);
        $action->handle(auth()->user(), $submission);

        return back()->with('success', 'A new submission revision is ready.');
    }

    private function ensureScope(SchoolClass $class, ClassSubject $subject, Assignment $assignment): void
    {
        abort_unless($subject->school_class_id === $class->id && $assignment->class_subject_id === $subject->id, 404);
    }

    private function ensureSubmissionScope(SchoolClass $class, ClassSubject $subject, Assignment $assignment, AssignmentSubmission $submission): void
    {
        $this->ensureScope($class, $subject, $assignment);
        abort_unless($submission->assignment_id === $assignment->id, 404);
    }
}

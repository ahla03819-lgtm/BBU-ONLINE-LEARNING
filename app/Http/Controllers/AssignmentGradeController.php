<?php

namespace App\Http\Controllers;

use App\Actions\Coursework\RecordAssignmentGrade;
use App\Http\Requests\Coursework\GradeSubmissionRequest;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;

class AssignmentGradeController extends Controller
{
    public function store(GradeSubmissionRequest $request, SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, AssignmentSubmission $submission, RecordAssignmentGrade $action): RedirectResponse
    {
        abort_unless($classSubject->school_class_id === $schoolClass->id && $assignment->class_subject_id === $classSubject->id && $submission->assignment_id === $assignment->id, 404);
        $action->handle($request->user(), $submission, $request->validated());

        return back()->with('success', 'Grade recorded.');
    }
}

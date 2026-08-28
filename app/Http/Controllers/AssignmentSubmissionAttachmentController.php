<?php

namespace App\Http\Controllers;

use App\Actions\Coursework\RemoveDraftAttachment;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\AssignmentSubmissionAttachment;
use App\Models\AssignmentSubmissionRevision;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentSubmissionAttachmentController extends Controller
{
    public function download(SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, AssignmentSubmission $submission, AssignmentSubmissionRevision $revision, AssignmentSubmissionAttachment $attachment): StreamedResponse
    {
        $this->ensureScope($schoolClass, $classSubject, $assignment, $submission, $revision, $attachment);
        $this->authorize('view', $attachment);
        $this->ensureObject($attachment);

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, $this->headers($attachment));
    }

    public function preview(SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, AssignmentSubmission $submission, AssignmentSubmissionRevision $revision, AssignmentSubmissionAttachment $attachment): StreamedResponse
    {
        $this->ensureScope($schoolClass, $classSubject, $assignment, $submission, $revision, $attachment);
        $this->authorize('view', $attachment);
        abort_unless($attachment->isPreviewable(), 404);
        $this->ensureObject($attachment);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, $this->headers($attachment), 'inline');
    }

    public function destroy(SchoolClass $schoolClass, ClassSubject $classSubject, Assignment $assignment, AssignmentSubmission $submission, AssignmentSubmissionRevision $revision, AssignmentSubmissionAttachment $attachment, RemoveDraftAttachment $action): RedirectResponse
    {
        $this->ensureScope($schoolClass, $classSubject, $assignment, $submission, $revision, $attachment);
        $action->handle(auth()->user(), $attachment);

        return back()->with('success', 'Draft attachment removed.');
    }

    private function ensureScope($class, $subject, $assignment, $submission, $revision, $attachment): void
    {
        abort_unless($subject->school_class_id === $class->id && $assignment->class_subject_id === $subject->id && $submission->assignment_id === $assignment->id && $revision->assignment_submission_id === $submission->id && $attachment->assignment_submission_revision_id === $revision->id, 404);
    }

    private function ensureObject($attachment): void
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404, 'Attachment unavailable.');
    }

    private function headers($attachment): array
    {
        return ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];
    }
}

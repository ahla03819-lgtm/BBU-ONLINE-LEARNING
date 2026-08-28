<?php

namespace App\Actions\Coursework;

use App\Enums\SubmissionStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Coursework\CourseworkAttachmentInspector;
use App\Services\Coursework\CourseworkAttachmentStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SaveSubmissionDraft
{
    public function __construct(private CourseworkAttachmentInspector $inspector, private CourseworkAttachmentStorage $storage, private AuditLogger $audit) {}

    public function handle(User $student, Assignment $assignment, array $data, array $files = []): AssignmentSubmission
    {
        $profile = $student->studentProfile()->firstOrFail();
        $existing = AssignmentSubmission::query()->where('assignment_id', $assignment->id)->where('student_profile_id', $profile->id)->first();
        Gate::forUser($student)->authorize($existing ? 'update' : 'create', $existing ?: [AssignmentSubmission::class, $assignment]);
        $candidates = [];
        foreach ($files as $position => $file) {
            $candidates[] = $this->inspector->inspect($file, $data['attachment_client_uuids'][$position], $position);
        }

        return DB::transaction(function () use ($student, $assignment, $profile, $existing, $data, $candidates) {
            $identity = $existing ?: AssignmentSubmission::query()->firstOrCreate(['assignment_id' => $assignment->id, 'student_profile_id' => $profile->id]);
            $submission = AssignmentSubmission::query()->lockForUpdate()->findOrFail($identity->id);
            Gate::forUser($student)->authorize($existing ? 'update' : 'create', $existing ?: [AssignmentSubmission::class, $assignment]);
            $draft = $submission->revisions()->where('draft_slot', 1)->lockForUpdate()->first();
            if (! $draft) {
                $number = $submission->latest_revision_number + 1;
                $draft = $submission->revisions()->create(['revision_number' => $number, 'client_uuid' => $data['client_uuid'], 'authored_by' => $student->id, 'status' => SubmissionStatus::Draft, 'draft_slot' => 1]);
                $submission->update(['latest_revision_number' => $number, 'status' => SubmissionStatus::Draft, 'lock_version' => $submission->lock_version + 1]);
            }
            $existingBytes = (int) $draft->attachments()->sum('size_bytes');
            if ($draft->attachments()->count() + count($candidates) > config('coursework-attachments.max_files') || $existingBytes + array_sum(array_column($candidates, 'size_bytes')) > config('coursework-attachments.max_combined_bytes')) {
                throw ValidationException::withMessages(['attachments' => 'The draft attachment total exceeds the allowed limit.']);
            }
            $draft->update(['body' => $data['body'] ?? null, 'lock_version' => $draft->lock_version + 1]);
            $stored = [];
            try {
                foreach ($candidates as $candidate) {
                    $stored[] = $this->storage->store($submission, $draft->revision_number, $candidate);
                }
                foreach ($stored as $item) {
                    $lastPosition = $draft->attachments()->max('position');
                    $draft->attachments()->create(['uploaded_by' => $student->id, 'client_uuid' => $item['client_uuid'], 'disk' => $item['disk'], 'path' => $item['path'], 'original_name' => $item['original_name'], 'extension' => $item['extension'], 'mime_type' => $item['mime_type'], 'size_bytes' => $item['size_bytes'], 'sha256' => $item['sha256'], 'position' => $lastPosition === null ? 0 : $lastPosition + 1]);
                }
            } catch (\Throwable $e) {
                $this->storage->deleteMany($stored);
                throw $e;
            }
            $this->audit->log('submission.draft-saved', $submission, [], ['assignment_id' => $assignment->id, 'revision_number' => $draft->revision_number, 'attachment_count' => count($stored)]);

            return $submission->refresh();
        });
    }
}

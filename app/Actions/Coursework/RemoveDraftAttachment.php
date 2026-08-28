<?php

namespace App\Actions\Coursework;

use App\Models\AssignmentSubmissionAttachment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class RemoveDraftAttachment
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, AssignmentSubmissionAttachment $attachment): void
    {
        Gate::forUser($actor)->authorize('delete', $attachment);
        DB::transaction(function () use ($attachment) {
            $locked = AssignmentSubmissionAttachment::query()->lockForUpdate()->findOrFail($attachment->id);
            $disk = $locked->disk;
            $path = $locked->path;
            $this->audit->log('submission.attachment-removed', $locked, ['revision_id' => $locked->assignment_submission_revision_id], []);
            $locked->delete();
            Storage::disk($disk)->delete($path);
        });
    }
}

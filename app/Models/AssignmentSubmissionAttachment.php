<?php

namespace App\Models;

use Database\Factories\AssignmentSubmissionAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_submission_revision_id', 'uploaded_by', 'client_uuid', 'disk', 'path', 'original_name', 'extension', 'mime_type', 'size_bytes', 'sha256', 'position'])]
class AssignmentSubmissionAttachment extends Model
{
    /** @use HasFactory<AssignmentSubmissionAttachmentFactory> */
    use HasFactory;

    public const PREVIEWABLE = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain', 'text/csv'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(AssignmentSubmissionRevision::class, 'assignment_submission_revision_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPreviewable(): bool
    {
        return in_array($this->mime_type, self::PREVIEWABLE, true);
    }
}

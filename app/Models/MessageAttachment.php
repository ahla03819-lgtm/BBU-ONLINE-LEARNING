<?php

namespace App\Models;

use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['message_id', 'uploaded_by', 'client_uuid', 'disk', 'path', 'original_name', 'extension', 'mime_type', 'size_bytes', 'sha256', 'position'])]
class MessageAttachment extends Model
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'position' => 'integer'];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPreviewable(): bool
    {
        return in_array($this->extension, ['jpg', 'jpeg', 'png', 'webp'], true);
    }

    public function category(): string
    {
        return $this->isPreviewable() ? 'image' : (in_array($this->extension, ['docx', 'xlsx', 'pptx'], true) ? 'office' : (in_array($this->extension, ['txt', 'csv'], true) ? 'text' : 'document'));
    }
}

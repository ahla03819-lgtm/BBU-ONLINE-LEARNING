<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\SchoolClass;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentController extends Controller
{
    public function download(SchoolClass $schoolClass, Channel $channel, Message $message, MessageAttachment $attachment, AuditLogger $audit): StreamedResponse
    {
        $this->ensureScope($schoolClass, $channel, $message, $attachment);
        $this->authorize('view', $attachment);
        $this->ensureObject($attachment);
        if ($message->isHidden()) {
            $audit->log('message_attachment.hidden_accessed', $attachment, [], ['message_id' => $message->id, 'channel_id' => $channel->id]);
        }

        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function preview(SchoolClass $schoolClass, Channel $channel, Message $message, MessageAttachment $attachment): StreamedResponse
    {
        $this->ensureScope($schoolClass, $channel, $message, $attachment);
        $this->authorize('view', $attachment);
        abort_unless($attachment->isPreviewable(), 404);
        $this->ensureObject($attachment);

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'], 'inline');
    }

    private function ensureScope(SchoolClass $class, Channel $channel, Message $message, MessageAttachment $attachment): void
    {
        abort_unless($channel->school_class_id === $class->id && $message->channel_id === $channel->id && $attachment->message_id === $message->id, 404);
    }

    private function ensureObject(MessageAttachment $attachment): void
    {
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404, 'Attachment unavailable.');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessageAttachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConversationAttachmentController extends Controller
{
    public function show(Conversation $conversation, ConversationMessageAttachment $attachment): StreamedResponse
    {
        $this->authorize('view', $conversation);
        abort_unless($attachment->message->conversation_id === $conversation->id, 404);
        abort_if($attachment->message->deleted_at !== null, 404);
        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);
        $headers = ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        $disposition = in_array($attachment->attachment_type, ['image', 'voice', 'video'], true) ? 'inline' : 'attachment';
        $disk = Storage::disk($attachment->disk);
        $response = response()->stream(function () use ($disk, $attachment): void {
            $stream = $disk->readStream($attachment->path);

            try {
                while (! feof($stream)) {
                    echo fread($stream, 1024 * 1024);
                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, 200, $headers + ['Content-Length' => (string) $attachment->size_bytes]);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition($disposition, $attachment->original_name));

        return $response;
    }
}

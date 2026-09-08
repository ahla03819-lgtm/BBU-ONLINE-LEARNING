<?php

namespace App\Services\Conversations;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ConversationAttachmentStorage
{
    public function store(UploadedFile $file, string $conversationUuid, string $messageKey): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = strtolower(trim(explode(';', (string) $file->getMimeType(), 2)[0]));
        // Safari and some Chromium builds identify an audio-only MediaRecorder WebM
        // upload as video/webm. The client gives recordings this controlled filename.
        $isBrowserVoiceRecording = $extension === 'webm'
            && $mimeType === 'video/webm'
            && preg_match('/^voice-message-\d+\.webm$/i', $file->getClientOriginalName()) === 1;
        $type = str_starts_with($mimeType, 'image/') ? 'image' : (str_starts_with($mimeType, 'audio/') || $isBrowserVoiceRecording ? 'voice' : (str_starts_with($mimeType, 'video/') ? 'video' : 'file'));
        $disk = 'local';
        $path = "conversation-attachments/{$conversationUuid}/{$messageKey}/".Str::uuid().'.'.$extension;
        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        return ['disk' => $disk, 'path' => $path, 'original_name' => basename(str_replace(['/', '\\'], '-', $file->getClientOriginalName())), 'extension' => $extension, 'mime_type' => $mimeType, 'size_bytes' => $file->getSize(), 'attachment_type' => $type];
    }
}

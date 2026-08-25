<?php

namespace App\Services\Attachments;

use App\Models\Channel;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class AttachmentStorage
{
    public function store(Channel $channel, string $messageClientUuid, array $candidate): array
    {
        $disk = config('message-attachments.disk');
        $path = "message-attachments/{$channel->school_class_id}/{$channel->id}/{$messageClientUuid}/".Str::uuid();
        $stream = fopen($candidate['file']->getRealPath(), 'rb');
        try {
            if (! Storage::disk($disk)->put($path, $stream)) {
                throw new RuntimeException('The attachment could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [...$candidate, 'disk' => $disk, 'path' => $path];
    }

    public function deleteMany(array $stored): void
    {
        foreach ($stored as $item) {
            if (isset($item['disk'], $item['path'])) {
                Storage::disk($item['disk'])->delete($item['path']);
            }
        }
    }
}

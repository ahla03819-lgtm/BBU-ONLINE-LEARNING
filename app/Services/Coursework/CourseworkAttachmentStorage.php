<?php

namespace App\Services\Coursework;

use App\Models\AssignmentSubmission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class CourseworkAttachmentStorage
{
    public function store(AssignmentSubmission $submission, int $revisionNumber, array $candidate): array
    {
        $disk = config('coursework-attachments.disk');
        $assignment = $submission->assignment()->with('classSubject')->firstOrFail();
        $path = "coursework-submissions/{$assignment->classSubject->school_class_id}/{$assignment->id}/{$submission->id}/{$revisionNumber}/".Str::uuid();
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

    public function deleteMany(array $items): void
    {
        foreach ($items as $item) {
            Storage::disk($item['disk'])->delete($item['path']);
        }
    }
}

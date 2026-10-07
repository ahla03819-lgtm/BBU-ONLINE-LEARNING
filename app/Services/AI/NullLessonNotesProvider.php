<?php

namespace App\Services\AI;

use App\Contracts\LessonNotesProvider;
use App\Models\Meeting;

final class NullLessonNotesProvider implements LessonNotesProvider
{
    public function generateNotes(Meeting $meeting, array $transcriptSegments, string $language, array $context = []): array
    {
        return [
            'content' => '',
            'language' => $language,
            'status' => 'unavailable',
        ];
    }
}

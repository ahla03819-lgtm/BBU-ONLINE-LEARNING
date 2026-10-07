<?php

namespace App\Contracts;

use App\Models\Meeting;

interface LessonNotesProvider
{
    /**
     * @return array{content: string, language: string, status: string}
     */
    public function generateNotes(Meeting $meeting, array $transcriptSegments, string $language, array $context = []): array;
}

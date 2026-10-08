<?php

namespace App\Contracts;

use App\Models\Meeting;

interface LessonSummaryProvider
{
    /**
     * @return array{content: string, language: string}
     */
    public function generateSummary(Meeting $meeting, array $transcriptSegments, string $language, array $context = []): array;
}

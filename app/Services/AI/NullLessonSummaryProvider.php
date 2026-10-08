<?php

namespace App\Services\AI;

use App\Contracts\LessonSummaryProvider;
use App\Models\Meeting;

final class NullLessonSummaryProvider implements LessonSummaryProvider
{
    public function generateSummary(Meeting $meeting, array $transcriptSegments, string $language, array $context = []): array
    {
        return [
            'content' => '',
            'language' => $language,
        ];
    }
}

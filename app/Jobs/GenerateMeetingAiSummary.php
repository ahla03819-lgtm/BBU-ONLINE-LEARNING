<?php

namespace App\Jobs;

use App\Contracts\LessonSummaryProvider;
use App\Models\MeetingAiSummary;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateMeetingAiSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 45];

    public function __construct(public int $summaryId, public string $language = 'en') {}

    public function handle(LessonSummaryProvider $summaryProvider): void
    {
        $summary = MeetingAiSummary::query()->find($this->summaryId);

        if (! $summary || $summary->status === 'ready' || $summary->status === 'failed') {
            return;
        }

        $meeting = $summary->meeting()->with(['transcripts'])->first();

        if (! $meeting) {
            $summary->update(['status' => 'failed', 'provider_metadata' => ['error' => 'Meeting not found.']]);

            return;
        }

        $segments = $meeting->transcripts()
            ->orderBy('sequence')
            ->get()
            ->map(fn ($segment) => [
                'speaker' => $segment->speaker_display_name,
                'text' => $segment->original_text,
                'language' => $segment->original_language,
                'started_at' => $segment->started_at?->toIso8601String(),
                'ended_at' => $segment->ended_at?->toIso8601String(),
            ])
            ->all();

        try {
            $result = $summaryProvider->generateSummary($meeting, $segments, $this->language);

            $summary->update([
                'content' => (string) ($result['content'] ?? ''),
                'status' => 'ready',
                'provider' => 'null',
                'provider_metadata' => [
                    'language' => $result['language'] ?? $this->language,
                    'segment_count' => count($segments),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('AI summary generation failed', [
                'summary_id' => $this->summaryId,
                'meeting_id' => $meeting->id,
                'error' => $exception->getMessage(),
            ]);

            $summary->update([
                'status' => 'failed',
                'provider_metadata' => ['error' => 'AI summary generation failed.'],
            ]);
        }
    }
}

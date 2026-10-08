<?php

namespace App\Jobs;

use App\Contracts\LessonNotesProvider;
use App\Models\MeetingAiNote;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateMeetingAiNotes implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 45];

    public function __construct(public int $noteId, public string $language = 'en') {}

    public function handle(LessonNotesProvider $notesProvider): void
    {
        $note = MeetingAiNote::query()->find($this->noteId);

        if (! $note || $note->status === 'ready' || $note->status === 'failed') {
            return;
        }

        $meeting = $note->meeting()->with(['transcripts'])->first();

        if (! $meeting) {
            $note->update(['status' => 'failed', 'provider_metadata' => ['error' => 'Meeting not found.']]);

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
            $result = $notesProvider->generateNotes($meeting, $segments, $this->language);

            $note->update([
                'content' => (string) ($result['content'] ?? ''),
                'status' => (string) ($result['status'] ?? 'ready'),
                'provider' => 'null',
                'provider_metadata' => [
                    'language' => $result['language'] ?? $this->language,
                    'segment_count' => count($segments),
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('AI notes generation failed', [
                'note_id' => $this->noteId,
                'meeting_id' => $meeting->id,
                'error' => $exception->getMessage(),
            ]);

            $note->update([
                'status' => 'failed',
                'provider_metadata' => ['error' => 'AI notes generation failed.'],
            ]);
        }
    }
}

<?php

namespace App\Jobs;

use App\Contracts\TranslationProvider;
use App\Models\MeetingTranscript;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class TranslateTranscriptSegment implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [10, 30];

    public function __construct(public int $segmentId, public string $targetLanguage) {}

    public function handle(TranslationProvider $translationProvider): void
    {
        $segment = MeetingTranscript::query()->find($this->segmentId);

        if (! $segment || $segment->translated_language || $segment->translated_text) {
            return;
        }

        $sourceLanguage = $segment->original_language;

        if ($sourceLanguage === $this->targetLanguage) {
            return;
        }

        try {
            $result = $translationProvider->translate(
                $segment->original_text,
                $sourceLanguage,
                $this->targetLanguage,
                ['speakerIdentity' => $segment->speaker_identity]
            );

            $translatedText = (string) ($result['text'] ?? '');

            if ($translatedText === '') {
                Log::info('Translation provider returned empty result', [
                    'segment_id' => $this->segmentId,
                    'source' => $sourceLanguage,
                    'target' => $this->targetLanguage,
                ]);

                return;
            }

            $segment->update([
                'translated_language' => $this->targetLanguage,
                'translated_text' => $translatedText,
            ]);
        } catch (\Throwable $exception) {
            Log::error('Transcript translation failed', [
                'segment_id' => $this->segmentId,
                'source' => $sourceLanguage,
                'target' => $this->targetLanguage,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

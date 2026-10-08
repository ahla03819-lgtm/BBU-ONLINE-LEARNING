<?php

namespace Tests\Feature\PhaseAI;

use App\Contracts\LessonNotesProvider;
use App\Contracts\LessonSummaryProvider;
use App\Contracts\TranslationProvider;
use App\Jobs\GenerateMeetingAiNotes;
use App\Jobs\GenerateMeetingAiSummary;
use App\Jobs\TranslateTranscriptSegment;
use App\Models\Meeting;
use App\Models\MeetingAiNote;
use App\Models\MeetingAiSummary;
use App\Models\MeetingTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingAiJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_meeting_ai_notes_success_updates_existing_record(): void
    {
        $meeting = Meeting::factory()->create();
        MeetingTranscript::factory()->forMeeting($meeting)->create([
            'original_text' => 'Welcome to class.',
            'original_language' => 'en',
            'sequence' => 1,
        ]);

        $note = MeetingAiNote::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'content' => '',
            'status' => 'generating',
            'generated_by' => null,
            'provider' => null,
            'provider_metadata' => null,
        ]);

        $job = new GenerateMeetingAiNotes($note->id, 'en');
        $job->handle(new FakeLessonNotesProvider());

        $fresh = $note->fresh();

        $this->assertSame('ready', $fresh->status);
        $this->assertSame('Fake AI notes content', $fresh->content);
        $this->assertSame('null', $fresh->provider);
        $this->assertSame('en', $fresh->provider_metadata['language']);
        $this->assertSame(1, $fresh->provider_metadata['segment_count']);

        $this->assertSame(1, $meeting->aiNotes()->count());
    }

    public function test_generate_meeting_ai_notes_provider_failure_sets_failed_status(): void
    {
        $meeting = Meeting::factory()->create();

        $note = MeetingAiNote::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'content' => '',
            'status' => 'generating',
            'generated_by' => null,
            'provider' => null,
            'provider_metadata' => null,
        ]);

        $job = new GenerateMeetingAiNotes($note->id, 'en');
        $job->handle(new FakeLessonNotesProvider(new \RuntimeException('secret upstream token')));

        $fresh = $note->fresh();

        $this->assertSame('failed', $fresh->status);
        $this->assertSame('AI notes generation failed.', $fresh->provider_metadata['error']);
        $this->assertStringNotContainsString('secret upstream token', (string) $fresh->provider_metadata['error']);
        $this->assertStringNotContainsString('secret upstream token', $fresh->content);

        $this->assertSame(1, $meeting->aiNotes()->count());
    }

    public function test_generate_meeting_ai_notes_retry_does_not_reprocess_ready_note(): void
    {
        $meeting = Meeting::factory()->create();

        $note = MeetingAiNote::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'content' => 'Already generated',
            'status' => 'ready',
            'generated_by' => null,
            'provider' => 'null',
            'provider_metadata' => null,
        ]);

        $job = new GenerateMeetingAiNotes($note->id, 'en');
        $job->handle(new FakeLessonNotesProvider());

        $fresh = $note->fresh();

        $this->assertSame('ready', $fresh->status);
        $this->assertSame('Already generated', $fresh->content);
        $this->assertSame('null', $fresh->provider);

        $this->assertSame(1, $meeting->aiNotes()->count());
    }

    public function test_generate_meeting_ai_summary_success_updates_existing_record(): void
    {
        $meeting = Meeting::factory()->create();
        MeetingTranscript::factory()->forMeeting($meeting)->create([
            'original_text' => 'Welcome to class.',
            'original_language' => 'en',
            'sequence' => 1,
        ]);

        $summary = MeetingAiSummary::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'status' => 'generating',
            'content' => '',
            'generated_by' => null,
            'provider' => null,
            'provider_metadata' => null,
        ]);

        $job = new GenerateMeetingAiSummary($summary->id, 'en');
        $job->handle(new FakeLessonSummaryProvider());

        $fresh = $summary->fresh();

        $this->assertSame('ready', $fresh->status);
        $this->assertSame('Fake AI summary content', $fresh->content);
        $this->assertSame('null', $fresh->provider);
        $this->assertSame('en', $fresh->provider_metadata['language']);
        $this->assertSame(1, $fresh->provider_metadata['segment_count']);

        $this->assertSame(1, $meeting->aiSummaries()->count());
    }

    public function test_generate_meeting_ai_summary_provider_failure_sets_failed_status(): void
    {
        $meeting = Meeting::factory()->create();

        $summary = MeetingAiSummary::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'status' => 'generating',
            'content' => '',
            'generated_by' => null,
            'provider' => null,
            'provider_metadata' => null,
        ]);

        $job = new GenerateMeetingAiSummary($summary->id, 'en');
        $job->handle(new FakeLessonSummaryProvider(new \RuntimeException('secret upstream token')));

        $fresh = $summary->fresh();

        $this->assertSame('failed', $fresh->status);
        $this->assertSame('AI summary generation failed.', $fresh->provider_metadata['error']);
        $this->assertStringNotContainsString('secret upstream token', (string) $fresh->provider_metadata['error']);
        $this->assertStringNotContainsString('secret upstream token', $fresh->content);

        $this->assertSame(1, $meeting->aiSummaries()->count());
    }

    public function test_generate_meeting_ai_summary_retry_does_not_reprocess_ready_summary(): void
    {
        $meeting = Meeting::factory()->create();

        $summary = MeetingAiSummary::create([
            'meeting_id' => $meeting->id,
            'language' => 'en',
            'status' => 'ready',
            'content' => 'Already generated',
            'generated_by' => null,
            'provider' => 'null',
            'provider_metadata' => null,
        ]);

        $job = new GenerateMeetingAiSummary($summary->id, 'en');
        $job->handle(new FakeLessonSummaryProvider());

        $fresh = $summary->fresh();

        $this->assertSame('ready', $fresh->status);
        $this->assertSame('Already generated', $fresh->content);
        $this->assertSame('null', $fresh->provider);

        $this->assertSame(1, $meeting->aiSummaries()->count());
    }

    public function test_translate_transcript_segment_success_stores_translation_and_keeps_original(): void
    {
        $meeting = Meeting::factory()->create();
        $segment = MeetingTranscript::factory()->forMeeting($meeting)->create([
            'speaker_identity' => 'speaker-1',
            'speaker_display_name' => 'Teacher',
            'original_language' => 'en',
            'original_text' => 'Welcome to class.',
            'translated_language' => null,
            'translated_text' => null,
            'sequence' => 1,
        ]);

        $job = new TranslateTranscriptSegment($segment->id, 'km');
        $job->handle(new FakeTranslationProvider());

        $fresh = $segment->fresh();

        $this->assertSame('Welcome to class.', $fresh->original_text);
        $this->assertSame('en', $fresh->original_language);
        $this->assertSame('km', $fresh->translated_language);
        $this->assertSame('Translated: Welcome to class.', $fresh->translated_text);

        $this->assertSame(1, $meeting->transcripts()->count());
    }

    public function test_translate_transcript_segment_failure_preserves_original(): void
    {
        $meeting = Meeting::factory()->create();
        $segment = MeetingTranscript::factory()->forMeeting($meeting)->create([
            'original_language' => 'en',
            'original_text' => 'Welcome to class.',
            'translated_language' => null,
            'translated_text' => null,
            'sequence' => 1,
        ]);

        $job = new TranslateTranscriptSegment($segment->id, 'km');
        $job->handle(new FakeTranslationProvider(new \RuntimeException('translation upstream error')));

        $fresh = $segment->fresh();

        $this->assertSame('Welcome to class.', $fresh->original_text);
        $this->assertNull($fresh->translated_language);
        $this->assertNull($fresh->translated_text);

        $this->assertSame(1, $meeting->transcripts()->count());
    }

    public function test_translate_transcript_segment_repeated_execution_does_not_corrupt_or_duplicate(): void
    {
        $meeting = Meeting::factory()->create();
        $segment = MeetingTranscript::factory()->forMeeting($meeting)->create([
            'original_language' => 'en',
            'original_text' => 'Welcome to class.',
            'translated_language' => null,
            'translated_text' => null,
            'sequence' => 1,
        ]);

        $job = new TranslateTranscriptSegment($segment->id, 'km');
        $job->handle(new FakeTranslationProvider());
        $job->handle(new FakeTranslationProvider());

        $fresh = $segment->fresh();

        $this->assertSame('Welcome to class.', $fresh->original_text);
        $this->assertSame('km', $fresh->translated_language);
        $this->assertSame('Translated: Welcome to class.', $fresh->translated_text);

        $this->assertSame(1, $meeting->transcripts()->count());
    }

    public function test_translate_transcript_segment_same_language_is_skipped(): void
    {
        $meeting = Meeting::factory()->create();
        $segment = MeetingTranscript::factory()->forMeeting($meeting)->create([
            'original_language' => 'en',
            'original_text' => 'Welcome to class.',
            'translated_language' => null,
            'translated_text' => null,
            'sequence' => 1,
        ]);

        $job = new TranslateTranscriptSegment($segment->id, 'en');
        $job->handle(new FakeTranslationProvider());

        $fresh = $segment->fresh();

        $this->assertSame('Welcome to class.', $fresh->original_text);
        $this->assertNull($fresh->translated_language);
        $this->assertNull($fresh->translated_text);

        $this->assertSame(1, $meeting->transcripts()->count());
    }
}

final class FakeLessonNotesProvider implements LessonNotesProvider
{
    public function __construct(private ?\Throwable $exception = null) {}

    public function generateNotes(Meeting $meeting, array $transcriptSegments, string $language, array $context = []): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return [
            'content' => 'Fake AI notes content',
            'language' => $language,
            'status' => 'ready',
        ];
    }
}

final class FakeLessonSummaryProvider implements LessonSummaryProvider
{
    public function __construct(private ?\Throwable $exception = null) {}

    public function generateSummary(Meeting $meeting, array $transcriptSegments, string $language, array $context = []): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return [
            'content' => 'Fake AI summary content',
            'language' => $language,
        ];
    }
}

final class FakeTranslationProvider implements TranslationProvider
{
    public function __construct(private ?\Throwable $exception = null) {}

    public function translate(string $text, string $sourceLanguage, string $targetLanguage, array $context = []): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return [
            'text' => 'Translated: '.$text,
            'language' => $targetLanguage,
            'sourceLanguage' => $sourceLanguage,
        ];
    }
}

<?php

namespace App\Actions\Meetings;

use App\Contracts\LessonSummaryProvider;
use App\Jobs\GenerateMeetingAiSummary;
use App\Models\Meeting;
use App\Models\MeetingAiSummary;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RequestMeetingAiSummary
{
    public function __construct(private LessonSummaryProvider $summaryProvider) {}

    public function handle(User $actor, Meeting $meeting, string $language = 'en'): MeetingAiSummary
    {
        Gate::forUser($actor)->authorize('generateAiSummary', $meeting);

        $existing = $meeting->aiSummaries()
            ->where('language', $language)
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $summary = new MeetingAiSummary([
            'meeting_id' => $meeting->id,
            'language' => $language,
            'status' => 'generating',
            'content' => '',
            'generated_by' => $actor->id,
            'provider' => null,
            'provider_metadata' => null,
        ]);

        $summary->save();

        GenerateMeetingAiSummary::dispatch($summary->id, $language);

        return $summary;
    }
}

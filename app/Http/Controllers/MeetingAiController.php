<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\RequestMeetingAiNotes;
use App\Actions\Meetings\RequestMeetingAiSummary;
use App\Actions\Meetings\StoreMeetingTranscriptSegment;
use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MeetingAiController extends Controller
{
    public function transcript(Request $request, SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        Gate::authorize('viewTranscript', $meeting);

        $segments = $meeting->transcripts()
            ->orderBy('sequence')
            ->orderBy('started_at')
            ->get(['id', 'speaker_identity', 'speaker_display_name', 'original_language', 'original_text', 'translated_language', 'translated_text', 'started_at', 'ended_at', 'sequence'])
            ->map(fn ($segment) => [
                'id' => $segment->id,
                'speaker_identity' => $segment->speaker_identity,
                'speaker_display_name' => $segment->speaker_display_name,
                'original_language' => $segment->original_language,
                'original_text' => $segment->original_text,
                'translated_language' => $segment->translated_language,
                'translated_text' => $segment->translated_text,
                'started_at' => $segment->started_at?->toIso8601String(),
                'ended_at' => $segment->ended_at?->toIso8601String(),
                'sequence' => $segment->sequence,
            ])
            ->values()
            ->all();

        return response()->json(['segments' => $segments]);
    }

    public function notes(Request $request, SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        Gate::authorize('viewAiNotes', $meeting);

        $note = $meeting->aiNotes()
            ->where('language', $request->query('language', 'en'))
            ->latest()
            ->first(['id', 'language', 'content', 'status', 'provider', 'provider_metadata', 'created_at', 'updated_at']);

        if (! $note) {
            return response()->json(['note' => null]);
        }

        return response()->json(['note' => [
            'id' => $note->id,
            'language' => $note->language,
            'content' => $note->content,
            'status' => $note->status,
            'provider' => $note->provider,
            'provider_metadata' => $note->provider_metadata,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ]]);
    }

    public function requestNotes(Request $request, SchoolClass $schoolClass, Meeting $meeting, RequestMeetingAiNotes $action): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        Gate::authorize('generateAiNotes', $meeting);

        $language = $request->query('language', 'en');

        $note = $action->handle($request->user(), $meeting, $language);

        return response()->json(['note' => [
            'id' => $note->id,
            'language' => $note->language,
            'status' => $note->status,
            'created_at' => $note->created_at?->toIso8601String(),
        ]]);
    }

    public function summary(Request $request, SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        Gate::authorize('viewAiSummary', $meeting);

        $summary = $meeting->aiSummaries()
            ->where('language', $request->query('language', 'en'))
            ->latest()
            ->first(['id', 'language', 'content', 'status', 'provider', 'provider_metadata', 'created_at', 'updated_at']);

        if (! $summary) {
            return response()->json(['summary' => null]);
        }

        return response()->json(['summary' => [
            'id' => $summary->id,
            'language' => $summary->language,
            'content' => $summary->content,
            'status' => $summary->status,
            'provider' => $summary->provider,
            'provider_metadata' => $summary->provider_metadata,
            'created_at' => $summary->created_at?->toIso8601String(),
            'updated_at' => $summary->updated_at?->toIso8601String(),
        ]]);
    }

    public function requestSummary(Request $request, SchoolClass $schoolClass, Meeting $meeting, RequestMeetingAiSummary $action): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        Gate::authorize('generateAiSummary', $meeting);

        $language = $request->query('language', 'en');

        $summary = $action->handle($request->user(), $meeting, $language);

        return response()->json(['summary' => [
            'id' => $summary->id,
            'language' => $summary->language,
            'status' => $summary->status,
            'created_at' => $summary->created_at?->toIso8601String(),
        ]]);
    }
}

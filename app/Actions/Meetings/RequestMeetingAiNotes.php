<?php

namespace App\Actions\Meetings;

use App\Contracts\LessonNotesProvider;
use App\Jobs\GenerateMeetingAiNotes;
use App\Models\Meeting;
use App\Models\MeetingAiNote;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RequestMeetingAiNotes
{
    public function __construct(private LessonNotesProvider $notesProvider) {}

    public function handle(User $actor, Meeting $meeting, string $language = 'en'): MeetingAiNote
    {
        Gate::forUser($actor)->authorize('generateAiNotes', $meeting);

        $existing = $meeting->aiNotes()
            ->where('status', 'generating')
            ->where('language', $language)
            ->latest()
            ->first();

        if ($existing) {
            return $existing;
        }

        $note = new MeetingAiNote([
            'meeting_id' => $meeting->id,
            'language' => $language,
            'content' => '',
            'status' => 'generating',
            'generated_by' => $actor->id,
            'provider' => null,
            'provider_metadata' => null,
        ]);

        $note->save();

        GenerateMeetingAiNotes::dispatch($note->id, $language);

        return $note;
    }
}

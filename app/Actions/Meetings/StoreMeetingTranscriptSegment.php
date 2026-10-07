<?php

namespace App\Actions\Meetings;

use App\Models\Meeting;
use App\Models\MeetingTranscript;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class StoreMeetingTranscriptSegment
{
    /**
     * @param array{meeting_id:int, speaker_identity:string, speaker_display_name:string, original_language:string, original_text:string, translated_language?:string|null, translated_text?:string|null, started_at?:string|null, ended_at?:string|null, sequence:int} $data
     */
    public function handle(User $actor, Meeting $meeting, array $data): MeetingTranscript
    {
        Gate::forUser($actor)->authorize('viewTranscript', $meeting);

        $segment = new MeetingTranscript([
            'meeting_id' => $meeting->id,
            'speaker_identity' => $data['speaker_identity'],
            'speaker_display_name' => $data['speaker_display_name'],
            'original_language' => $data['original_language'],
            'original_text' => $data['original_text'],
            'translated_language' => $data['translated_language'] ?? null,
            'translated_text' => $data['translated_text'] ?? null,
            'started_at' => $data['started_at'] ?? now(),
            'ended_at' => $data['ended_at'] ?? null,
            'sequence' => (int) ($data['sequence'] ?? 0),
        ]);

        $segment->save();

        return $segment;
    }
}

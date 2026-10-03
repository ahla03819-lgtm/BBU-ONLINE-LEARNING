<?php

namespace App\Http\Controllers;

use App\Actions\Meetings\RecordMeetingLeave;
use App\Actions\Recordings\StartMeetingRecording;
use App\Actions\Recordings\StopMeetingRecording;
use App\Enums\MeetingRecordingStopReason;
use App\Events\MeetingRecordingChanged;
use App\Http\Requests\Recordings\StartMeetingRecordingRequest;
use App\Models\Meeting;
use App\Models\MeetingRecording;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\MeetingRecordingProjection;
use App\Services\AuditLogger;
use App\Services\Recordings\MeetingRecordingFileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Meeting recording: the teacher's controls, the state every participant reads, and
 * the only route through which a recording file is ever served.
 *
 * Nothing here talks to LiveKit. The controllers authorise, delegate to the actions
 * that own the lifecycle, and project the result.
 */
class MeetingRecordingController extends Controller
{
    /**
     * Start a recording. The student's browser cannot reach a success response
     * here: startRecording requires the recording permission, which only
     * administrators and teachers hold.
     */
    public function store(StartMeetingRecordingRequest $request, SchoolClass $schoolClass, Meeting $meeting, StartMeetingRecording $start): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);

        $recording = $start->handle($request->user(), $meeting, $request->validated('duration_minutes'));

        MeetingRecordingChanged::dispatch($recording);

        return response()->json([
            'recording' => $this->projection($meeting, $request->user(), $recording),
            'server_now_at' => now()->toIso8601String(),
        ], 201);
    }

    /**
     * The state every participant reads.
     *
     * This is the bounded reconciliation fallback behind the realtime broadcast: a
     * client that missed an event, or whose websocket dropped, still converges on
     * the authoritative state, including the stored deadline its countdown is
     * derived from.
     */
    public function show(Request $request, SchoolClass $schoolClass, Meeting $meeting): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        $this->authorize('view', $meeting);

        $recording = MeetingRecording::query()
            ->activeFor($meeting->id)
            ->first()
            ?? MeetingRecording::query()->where('meeting_id', $meeting->id)->orderByDesc('id')->first();

        return response()->json([
            'recording' => $this->projection($meeting, $request->user(), $recording),
            'server_now_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Manual stop. Goes through exactly the same action as the deadline, an
     * explicit recorder leave and the host ending the meeting.
     */
    public function stop(Request $request, SchoolClass $schoolClass, Meeting $meeting, StopMeetingRecording $stop): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);

        $recording = MeetingRecording::query()->activeFor($meeting->id)->first();
        if (! $recording) {
            return response()->json([
                'recording' => null,
                'message' => 'This meeting is not being recorded.',
            ]);
        }

        $this->authorize('stopRecording', [$meeting, $recording]);

        $stopped = $stop->handle($recording, MeetingRecordingStopReason::Manual, $request->user());
        MeetingRecordingChanged::dispatch($stopped);

        return response()->json([
            'recording' => $this->projection($meeting, $request->user(), $stopped),
            'server_now_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * The application's explicit leave signal.
     *
     * Leaving a meeting is a client-side disconnect today, so the only server-side
     * evidence of intent is this call, and it is what lets a recording started by
     * this person be closed without inferring intent from a provider disconnect
     * that a refresh or a reconnect would produce identically.
     *
     * It is a recording concern only and deliberately changes nothing about the
     * meeting itself, so it can never interfere with the persistent-meeting
     * refresh and reconnect behaviour.
     */
    public function leave(Request $request, SchoolClass $schoolClass, Meeting $meeting, RecordMeetingLeave $leave): JsonResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        $this->authorize('view', $meeting);

        $recording = $leave->handle($request->user(), $meeting);
        if ($recording) {
            MeetingRecordingChanged::dispatch($recording);
        }

        return response()->json([
            'left' => true,
            'recording' => $this->projection($meeting, $request->user(), $recording),
            'server_now_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Hand a watchable recording to an authorised viewer.
     *
     * There is no signed or public URL anywhere in this feature. Playback is an
     * authenticated, authorised request against this route.
     *
     * Once authorized, the bytes are served in whichever way the recording's disk
     * allows. An object store that can pre-sign returns a short-lived read URL minted
     * for this request alone, so the recording never travels through the application
     * and the URL expires with the permission that produced it. A disk that cannot
     * pre-sign is streamed from private storage with the same no-store, nosniff
     * headers the attachment downloads use. Neither branch stores a permanent URL, so
     * possession of a recording reference never grants access on its own.
     */
    public function play(Request $request, SchoolClass $schoolClass, Meeting $meeting, MeetingRecording $recording, AuditLogger $audit, MeetingRecordingFileStore $files): StreamedResponse|RedirectResponse
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
        abort_unless($recording->meeting_id === $meeting->id, 404);

        $this->authorize('play', $recording);

        $disk = $files->disk();
        abort_unless($disk->exists($recording->storage_path), 404, 'Recording unavailable.');

        $audit->log('meeting.recording-played', $recording, [], [
            'meeting_id' => $meeting->id,
            'user_id' => $request->user()->id,
        ]);

        if ($url = $files->temporaryUrl($recording)) {
            return redirect()->away($url);
        }

        return $disk->download(
            $recording->storage_path,
            $recording->original_name ?: ($recording->public_uuid.'.'.config('meeting-recordings.file_type')),
            [
                'Content-Type' => $recording->mime_type ?: (string) config('meeting-recordings.mime_type'),
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function projection(Meeting $meeting, User $viewer, ?MeetingRecording $recording): ?array
    {
        $projection = MeetingRecordingProjection::make($recording);
        if (! $projection) {
            return null;
        }

        return MeetingRecordingProjection::withPermissions(
            $projection,
            $viewer->can('startRecording', $meeting),
            $recording && MeetingRecording::query()->activeFor($meeting->id)->whereKey($recording->id)->exists()
                && $viewer->can('stopRecording', [$meeting, $recording]),
        );
    }
}

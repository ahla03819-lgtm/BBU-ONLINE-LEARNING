<?php

namespace App\Http\Controllers;

use App\Actions\Recordings\DeleteMeetingRecording;
use App\Actions\Recordings\PurgeMeetingRecording;
use App\Actions\Recordings\RestoreMeetingRecording;
use App\Models\MeetingRecording;
use App\Services\MeetingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class RecordingTrashController extends Controller
{
    public function index(Request $request, MeetingAccess $access): Response
    {
        $this->authorize('viewTrash', MeetingRecording::class);
        MeetingRecording::requireTrashSchema();

        $user = $request->user();
        $query = MeetingRecording::onlyTrashed()
            ->whereIn('meeting_id', $access->meetingsFor($user)->select('id'))
            ->with(['meeting:id,title,school_class_id', 'meeting.schoolClass:id,name', 'deletedBy:id,name'])
            ->orderByDesc('deleted_at');
        $recordings = $query->paginate(20)->through(fn (MeetingRecording $recording) => [
            'public_uuid' => $recording->public_uuid,
            'original_name' => $recording->original_name,
            'started_at' => $recording->started_at?->toIso8601String(),
            'ready_at' => $recording->ready_at?->toIso8601String(),
            'deleted_at' => $recording->deleted_at?->toIso8601String(),
            'deleted_by' => $recording->deletedBy ? ['name' => $recording->deletedBy->name] : null,
            'meeting' => $recording->meeting ? [
                'title' => $recording->meeting->title,
                'school_class' => ['name' => $recording->meeting->schoolClass?->name],
            ] : null,
            'can_restore' => $user->can('restore', $recording),
        ]);

        return \Inertia\Inertia::render('Recordings/Trash', [
            'recordings' => $recordings,
        ]);
    }

    public function destroy(Request $request, $recording, DeleteMeetingRecording $delete): JsonResponse
    {
        $this->authorize('deleteAny', MeetingRecording::class);
        MeetingRecording::requireTrashSchema();
        $rec = $this->findRecording($recording, false);
        $this->authorize('delete', $rec);
        $deleted = $delete->handle($rec, $request->user(), $request->input('reason'));

        return response()->json(['recording' => $deleted]);
    }

    public function restore(Request $request, $recording, RestoreMeetingRecording $restore): JsonResponse
    {
        $this->authorize('restoreAny', MeetingRecording::class);
        MeetingRecording::requireTrashSchema();
        $rec = $this->findRecording($recording, true);
        $this->authorize('restore', $rec);
        $restored = $restore->handle($rec, $request->user());

        return response()->json(['recording' => $restored]);
    }

    public function purge(Request $request, $recording, PurgeMeetingRecording $purge): JsonResponse
    {
        $this->authorize('purgeAny', MeetingRecording::class);
        MeetingRecording::requireTrashSchema();
        $rec = $this->findRecording($recording, true);
        $this->authorize('purge', $rec);
        $purge->handle($rec, $request->user());

        return response()->json(['success' => true]);
    }

    private function findRecording(string $recording, bool $withTrashed): MeetingRecording
    {
        $query = fn () => $withTrashed ? MeetingRecording::withTrashed() : MeetingRecording::query();

        return $query()->where('public_uuid', $recording)->first()
            ?? $query()->findOrFail($recording);
    }
}

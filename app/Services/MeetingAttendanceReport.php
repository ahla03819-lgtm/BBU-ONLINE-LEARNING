<?php

namespace App\Services;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingAttendanceSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds a per-occurrence meeting attendance report.
 *
 * Attendance stays occurrence-based: each generated Meeting is its own
 * occurrence and no series-level aggregate is treated as a source of truth.
 * LiveKit-derived sessions remain authoritative; nothing is written back.
 */
class MeetingAttendanceReport
{
    public function __construct(private MeetingAttendanceAccess $access) {}

    /**
     * @return array{school_class: array, meeting: array, rows: array<int, array>, summary: array}
     */
    public function report(User $user, Meeting $meeting): array
    {
        abort_unless($this->access->canViewReport($user, $meeting), 403);

        $meeting->loadMissing(['schoolClass:id,name,section,academic_year_id', 'classSubject:id,school_class_id']);
        $timezone = config('calendar.default_timezone');
        $occurrenceDate = $this->access->occurrenceDate($meeting);
        $isCancelled = $meeting->status === MeetingStatus::Cancelled;

        $sessionsByUser = $this->sessionsByUserId($meeting);
        [$durationEnd, $durationSeconds, $durationIsAuthoritative] = $this->duration($meeting);

        $rows = $this->roster($meeting, $occurrenceDate)
            ->map(function (Collection $enrollments) use ($sessionsByUser, $durationSeconds, $durationIsAuthoritative, $durationEnd, $isCancelled) {
                $enrollment = $enrollments->first();
                $student = $enrollment->studentProfile;
                $sessions = $sessionsByUser[$student->user_id] ?? collect();
                $attendedSeconds = $this->attendedSeconds($sessions, $durationEnd);
                $joined = $sessions->pluck('joined_at')->filter()->sort();
                $left = $sessions->pluck('left_at')->filter()->sort();

                return [
                    'student_number' => $student->student_number,
                    'student_name' => $student->user?->name,
                    'status' => $this->status($isCancelled, $attendedSeconds),
                    'sessions_count' => $sessions->count(),
                    'attended_seconds' => $attendedSeconds,
                    'attended_duration' => $this->durationLabel($attendedSeconds),
                    'first_joined_at' => $joined->first()?->toIso8601String(),
                    'last_left_at' => $left->last()?->toIso8601String(),
                    'attendance_percentage' => $this->percentage($attendedSeconds, $durationSeconds, $durationIsAuthoritative, $isCancelled),
                ];
            })
            ->sortBy(fn (array $row) => $row['student_name'] ?? '')
            ->values()
            ->all();

        $attended = collect($rows)->where('status', 'attended')->count();

        return [
            'school_class' => [
                'id' => $meeting->schoolClass->id,
                'name' => $meeting->schoolClass->name,
                'section' => $meeting->schoolClass->section,
            ],
            'meeting' => [
                'uuid' => $meeting->uuid,
                'title' => $meeting->title,
                'status' => $meeting->status->value,
                'occurrence_date' => $occurrenceDate,
                'occurrence_timezone' => $timezone,
                'scheduled_start_at' => $meeting->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $meeting->scheduled_end_at?->toIso8601String(),
                'session_started_at' => $meeting->session_started_at?->toIso8601String(),
                'actual_start_at' => $meeting->actual_start_at?->toIso8601String(),
                'actual_end_at' => $meeting->actual_end_at?->toIso8601String(),
                'duration_seconds' => $durationSeconds,
                'duration_label' => $durationSeconds === null ? null : $this->durationLabel($durationSeconds),
                'duration_is_authoritative' => $durationIsAuthoritative,
                'is_cancelled' => $isCancelled,
            ],
            'rows' => $rows,
            'summary' => [
                'roster_count' => count($rows),
                'attended_count' => $attended,
                'absent_count' => collect($rows)->where('status', 'absent')->count(),
                'not_applicable_count' => collect($rows)->where('status', 'not_applicable')->count(),
            ],
        ];
    }

    /**
     * Students enrolled in the meeting's class on the occurrence date. Students
     * who never joined still appear because the roster comes from enrollment
     * history, not from MeetingParticipant rows.
     *
     * @return Collection<int, Collection<int, \App\Models\Enrollment>>
     */
    private function roster(Meeting $meeting, string $occurrenceDate): Collection
    {
        return $meeting->schoolClass->enrollments()
            ->where('academic_year_id', $meeting->schoolClass->academic_year_id)
            ->whereDate('enrolled_on', '<=', $occurrenceDate)
            ->where(fn (Builder $query) => $query
                ->whereNull('ended_on')
                ->orWhereDate('ended_on', '>=', $occurrenceDate))
            ->with('studentProfile.user:id,name')
            ->get()
            ->groupBy('student_profile_id');
    }

    /** @return Collection<int, Collection<int, MeetingAttendanceSession>> */
    private function sessionsByUserId(Meeting $meeting): Collection
    {
        return $meeting->participants()
            ->whereNotNull('user_id')
            ->with(['user:id', 'attendanceSessions' => fn ($query) => $query->orderBy('joined_at')])
            ->get()
            ->keyBy('user_id')
            ->map(fn ($participant) => $participant->attendanceSessions);
    }

    /**
     * The authoritative meeting duration window, or nulls when the meeting
     * never produced a live session (legacy data) so percentages are reported
     * as unavailable instead of being derived from a stale field.
     *
     * @return array{0: ?CarbonInterface, 1: ?int, 2: bool}
     */
    private function duration(Meeting $meeting): array
    {
        $end = match (true) {
            in_array($meeting->status, [MeetingStatus::Active, MeetingStatus::Ending], true) => now(),
            $meeting->status === MeetingStatus::Ended => $meeting->actual_end_at,
            default => null,
        };

        if (! $meeting->session_started_at || ! $end) {
            return [null, null, false];
        }

        $seconds = $end->getTimestamp() - $meeting->session_started_at->getTimestamp();

        return [$end, max(0, $seconds), true];
    }

    /**
     * Total attended seconds from merged intervals, so a reconnect that
     * overlaps an earlier session is never double counted.
     *
     * @param  Collection<int, MeetingAttendanceSession>  $sessions
     */
    private function attendedSeconds(Collection $sessions, ?CarbonInterface $openCutoff): int
    {
        $intervals = [];

        foreach ($sessions as $session) {
            if (! $session->joined_at) {
                continue;
            }

            $start = $session->joined_at->getTimestamp();
            // An open session is bounded by the meeting end (server now while live).
            $end = $session->left_at?->getTimestamp() ?? $openCutoff?->getTimestamp();

            if ($end === null || $end <= $start) {
                continue;
            }

            $intervals[] = [$start, $end];
        }

        sort($intervals);

        $total = 0;
        $currentStart = null;
        $currentEnd = null;

        foreach ($intervals as [$start, $end]) {
            if ($currentEnd === null) {
                [$currentStart, $currentEnd] = [$start, $end];
                continue;
            }

            if ($start <= $currentEnd) {
                $currentEnd = max($currentEnd, $end);
                continue;
            }

            $total += $currentEnd - $currentStart;
            [$currentStart, $currentEnd] = [$start, $end];
        }

        if ($currentEnd !== null) {
            $total += $currentEnd - $currentStart;
        }

        return $total;
    }

    private function status(bool $isCancelled, int $attendedSeconds): string
    {
        if ($isCancelled) {
            return 'not_applicable';
        }

        return $attendedSeconds > 0 ? 'attended' : 'absent';
    }

    private function percentage(int $attendedSeconds, ?int $durationSeconds, bool $durationIsAuthoritative, bool $isCancelled): ?float
    {
        if ($isCancelled || ! $durationIsAuthoritative) {
            return null;
        }

        if ($durationSeconds === null || $durationSeconds <= 0) {
            return 0.0;
        }

        return round(min(100, ($attendedSeconds / $durationSeconds) * 100), 2);
    }

    private function durationLabel(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return sprintf('%d:%02d:%02d', $hours, $minutes, $remaining);
    }
}

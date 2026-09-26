<?php

namespace App\Http\Controllers;

use App\Models\Meeting;
use App\Models\SchoolClass;
use App\Services\MeetingAttendanceReport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MeetingAttendanceController extends Controller
{
    public function show(Request $request, SchoolClass $schoolClass, Meeting $meeting, MeetingAttendanceReport $reports): Response
    {
        $this->assertScope($schoolClass, $meeting);

        $report = $reports->report($request->user(), $meeting);

        return Inertia::render('Meetings/Attendance', [
            'schoolClass' => $report['school_class'],
            'meeting' => $report['meeting'],
            'rows' => $report['rows'],
            'summary' => $report['summary'],
            'export_url' => route('meetings.attendance.export', [$schoolClass, $meeting]),
        ]);
    }

    public function export(Request $request, SchoolClass $schoolClass, Meeting $meeting, MeetingAttendanceReport $reports): StreamedResponse
    {
        $this->assertScope($schoolClass, $meeting);

        $report = $reports->report($request->user(), $meeting);

        return response()->streamDownload(function () use ($report): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, [
                'Student Number', 'Student Name', 'Attendance Status', 'First Joined At', 'Last Left At',
                'Sessions', 'Attended Seconds', 'Attended Duration', 'Meeting Duration', 'Attendance Percentage',
            ]);

            foreach ($report['rows'] as $row) {
                fputcsv($stream, array_map(fn ($value) => self::safeCsvValue((string) $value), [
                    $row['student_number'] ?? '',
                    $row['student_name'] ?? '',
                    $row['status'],
                    $row['first_joined_at'] ?? '',
                    $row['last_left_at'] ?? '',
                    $row['sessions_count'],
                    $row['attended_seconds'],
                    $row['attended_duration'],
                    $report['meeting']['duration_label'] ?? 'N/A',
                    $row['attendance_percentage'] === null ? 'N/A' : number_format($row['attendance_percentage'], 2, '.', '').'%',
                ]));
            }

            fclose($stream);
        }, $this->filename($report), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function assertScope(SchoolClass $schoolClass, Meeting $meeting): void
    {
        abort_unless($meeting->school_class_id === $schoolClass->id, 404);
    }

    /** @param array{meeting: array} $report */
    private function filename(array $report): string
    {
        $slug = Str::slug($report['meeting']['title']) ?: 'meeting';
        $date = $report['meeting']['occurrence_date'];

        return "meeting-attendance-{$slug}-{$date}.csv";
    }

    private static function safeCsvValue(string $value): string
    {
        return preg_match('/^\s*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}

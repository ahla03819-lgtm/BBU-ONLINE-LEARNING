<?php

namespace App\Actions\Attendance;

use App\Enums\AttendanceRegisterStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRegister;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\AttendanceAccess;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OpenAttendanceRegister
{
    public function __construct(private AttendanceAccess $access, private AuditLogger $audit) {}

    public function handle(User $actor, SchoolClass $schoolClass, string $attendanceDate): AttendanceRegister
    {
        Gate::forUser($actor)->authorize('record', [AttendanceRegister::class, $schoolClass]);

        return DB::transaction(function () use ($actor, $schoolClass, $attendanceDate) {
            $lockedClass = SchoolClass::query()->with('academicYear')->lockForUpdate()->findOrFail($schoolClass->id);
            Gate::forUser($actor)->authorize('record', [AttendanceRegister::class, $lockedClass]);
            $register = AttendanceRegister::query()->where('school_class_id', $lockedClass->id)->whereDate('attendance_date', $attendanceDate)->lockForUpdate()->first();
            if ($register) {
                return $register;
            }

            $register = AttendanceRegister::query()->create([
                'school_class_id' => $lockedClass->id,
                'attendance_date' => $attendanceDate,
                'status' => AttendanceRegisterStatus::Draft,
                'roster_snapshot_at' => now(),
                'opened_by' => $actor->id,
            ]);
            $enrollments = $lockedClass->enrollments()
                ->where('academic_year_id', $lockedClass->academic_year_id)
                ->whereDate('enrolled_on', '<=', $attendanceDate)
                ->where(fn (Builder $query) => $query->whereNull('ended_on')->orWhereDate('ended_on', '>=', $attendanceDate))
                ->with('studentProfile')
                ->lockForUpdate()
                ->get();
            foreach ($enrollments as $enrollment) {
                $register->records()->create([
                    'student_profile_id' => $enrollment->student_profile_id,
                    'enrollment_id' => $enrollment->id,
                    'status' => AttendanceStatus::Present,
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
            }
            $this->audit->log('attendance.register-opened', $register, [], [
                'school_class_id' => $lockedClass->id,
                'attendance_date' => $attendanceDate,
                'roster_count' => $enrollments->count(),
            ]);

            return $register->load('records');
        });
    }
}

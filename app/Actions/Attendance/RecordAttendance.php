<?php

namespace App\Actions\Attendance;

use App\Enums\AttendanceRegisterStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRegister;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordAttendance
{
    public function handle(User $actor, AttendanceRegister $register, AttendanceRecord $record, AttendanceStatus $status, ?string $reason): AttendanceRecord
    {
        if ($record->attendance_register_id !== $register->id) {
            abort(404);
        }
        Gate::forUser($actor)->authorize('record', $record);

        return DB::transaction(function () use ($actor, $register, $record, $status, $reason) {
            $locked = AttendanceRecord::query()->with('attendanceRegister.schoolClass.academicYear')->lockForUpdate()->findOrFail($record->id);
            if ($locked->attendance_register_id !== $register->id) {
                abort(404);
            }
            Gate::forUser($actor)->authorize('record', $locked);
            if ($locked->attendanceRegister->status !== AttendanceRegisterStatus::Draft) {
                throw ValidationException::withMessages(['register' => 'Finalized attendance records require a correction.']);
            }
            $locked->update(['status' => $status, 'reason' => $reason ? trim($reason) : null, 'recorded_by' => $actor->id, 'recorded_at' => now()]);

            return $locked->refresh();
        });
    }
}

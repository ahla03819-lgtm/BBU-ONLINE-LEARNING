<?php

namespace App\Actions\Attendance;

use App\Enums\AttendanceRegisterStatus;
use App\Enums\AttendanceStatus;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRegister;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CorrectAttendanceRecord
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(User $actor, AttendanceRegister $register, AttendanceRecord $record, AttendanceStatus $status, ?string $reason, string $correctionReason): AttendanceRecord
    {
        if ($record->attendance_register_id !== $register->id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $register, $record, $status, $reason, $correctionReason) {
            $locked = AttendanceRecord::query()->with('attendanceRegister.schoolClass.academicYear')->lockForUpdate()->findOrFail($record->id);
            if ($locked->attendance_register_id !== $register->id) {
                abort(404);
            }
            Gate::forUser($actor)->authorize('correct', $locked->attendanceRegister);
            if ($locked->attendanceRegister->status !== AttendanceRegisterStatus::Finalized) {
                throw ValidationException::withMessages(['register' => 'Only finalized attendance can be corrected.']);
            }
            $correctionReason = trim($correctionReason);
            if ($correctionReason === '') {
                throw ValidationException::withMessages(['correction_reason' => 'A correction reason is required.']);
            }
            $before = $locked->only('status', 'reason');
            $locked->revisions()->create([
                'previous_status' => $locked->status,
                'previous_reason' => $locked->reason,
                'new_status' => $status,
                'new_reason' => $reason ? trim($reason) : null,
                'corrected_by' => $actor->id,
                'correction_reason' => $correctionReason,
                'corrected_at' => now(),
            ]);
            $locked->update(['status' => $status, 'reason' => $reason ? trim($reason) : null, 'recorded_by' => $actor->id, 'recorded_at' => now()]);
            $this->audit->log('attendance.record-corrected', $locked, $before, ['status' => $status->value, 'has_reason' => $reason !== null]);

            return $locked->refresh();
        });
    }
}

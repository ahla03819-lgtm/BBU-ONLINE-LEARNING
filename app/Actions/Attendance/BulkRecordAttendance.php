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

class BulkRecordAttendance
{
    /**
     * @param  array<int, array{id: int, status: string, reason?: string|null}>  $records
     */
    public function handle(User $actor, AttendanceRegister $register, string $mode, array $records = []): AttendanceRegister
    {
        return DB::transaction(function () use ($actor, $register, $mode, $records) {
            $lockedRegister = AttendanceRegister::query()
                ->with('schoolClass.academicYear')
                ->lockForUpdate()
                ->findOrFail($register->id);

            Gate::forUser($actor)->authorize('record', $lockedRegister);

            if ($lockedRegister->status !== AttendanceRegisterStatus::Draft) {
                throw ValidationException::withMessages(['register' => 'Finalized attendance records require a correction.']);
            }

            $lockedRecords = AttendanceRecord::query()
                ->where('attendance_register_id', $lockedRegister->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($mode === 'mark_all_present') {
                $updates = $lockedRecords->map(fn (AttendanceRecord $record) => [
                    'id' => $record->id,
                    'status' => AttendanceStatus::Present,
                    'reason' => null,
                ]);
            } else {
                $updates = collect($records)->map(function (array $record) use ($lockedRecords) {
                    $id = (int) $record['id'];
                    if (! $lockedRecords->has($id)) {
                        throw ValidationException::withMessages(['records' => 'Every attendance record must belong to this register.']);
                    }

                    return [
                        'id' => $id,
                        'status' => AttendanceStatus::from($record['status']),
                        'reason' => isset($record['reason']) && trim((string) $record['reason']) !== '' ? trim((string) $record['reason']) : null,
                    ];
                });
            }

            foreach ($updates as $update) {
                $lockedRecords->get($update['id'])->update([
                    'status' => $update['status'],
                    'reason' => $update['reason'],
                    'recorded_by' => $actor->id,
                    'recorded_at' => now(),
                ]);
            }

            return $lockedRegister->refresh();
        });
    }
}

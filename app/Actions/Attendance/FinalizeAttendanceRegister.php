<?php

namespace App\Actions\Attendance;

use App\Enums\AttendanceRegisterStatus;
use App\Models\AttendanceRegister;
use App\Models\User;
use App\Services\AttendanceAccess;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FinalizeAttendanceRegister
{
    public function __construct(private AttendanceAccess $access, private AuditLogger $audit) {}

    public function handle(User $actor, AttendanceRegister $register): AttendanceRegister
    {
        return DB::transaction(function () use ($actor, $register) {
            $locked = AttendanceRegister::query()->with('schoolClass.academicYear')->lockForUpdate()->findOrFail($register->id);
            if ($locked->status === AttendanceRegisterStatus::Finalized) {
                abort_unless($actor->can('attendance.finalize') && $this->access->canMutateRegister($actor, $locked->schoolClass), 403);

                return $locked;
            }
            Gate::forUser($actor)->authorize('finalize', $locked);
            $locked->update(['status' => AttendanceRegisterStatus::Finalized, 'finalized_by' => $actor->id, 'finalized_at' => now()]);
            $this->audit->log('attendance.register-finalized', $locked, ['status' => AttendanceRegisterStatus::Draft->value], ['status' => AttendanceRegisterStatus::Finalized->value]);

            return $locked->refresh();
        });
    }
}

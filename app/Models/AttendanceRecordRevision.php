<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Database\Factories\AttendanceRecordRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['attendance_record_id', 'previous_status', 'previous_reason', 'new_status', 'new_reason', 'corrected_by', 'correction_reason', 'corrected_at'])]
class AttendanceRecordRevision extends Model
{
    /** @use HasFactory<AttendanceRecordRevisionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Attendance record revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Attendance record revisions are append-only.'));
    }

    protected function casts(): array
    {
        return [
            'previous_status' => AttendanceStatus::class,
            'new_status' => AttendanceStatus::class,
            'corrected_at' => 'datetime',
        ];
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}

<?php

namespace App\Models;

use App\Enums\AttendanceRegisterStatus;
use Database\Factories\AttendanceRegisterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['school_class_id', 'attendance_date', 'status', 'roster_snapshot_at', 'opened_by', 'finalized_by', 'finalized_at'])]
class AttendanceRegister extends Model
{
    /** @use HasFactory<AttendanceRegisterFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (AttendanceRegister $register): void {
            $register->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => AttendanceRegisterStatus::class,
            'roster_snapshot_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}

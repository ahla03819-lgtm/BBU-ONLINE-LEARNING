<?php

namespace App\Models;

use App\Enums\MeetingRecurrenceType;
use App\Enums\MeetingSeriesStatus;
use Database\Factories\MeetingSeriesFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['uuid', 'school_class_id', 'class_subject_id', 'created_by', 'host_user_id', 'title', 'description', 'recurrence_type', 'weekdays', 'starts_on', 'ends_on', 'local_start_time', 'duration_minutes', 'timezone', 'max_participants', 'status', 'lifecycle_version', 'split_from_series_id'])]
class MeetingSeries extends Model
{
    /** @use HasFactory<MeetingSeriesFactory> */
    use HasFactory;

    protected $table = 'meeting_series';

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (MeetingSeries $series): void {
            $series->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'recurrence_type' => MeetingRecurrenceType::class,
            'status' => MeetingSeriesStatus::class,
            'weekdays' => 'array',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'local_start_time' => 'string',
            'duration_minutes' => 'integer',
            'max_participants' => 'integer',
            'lifecycle_version' => 'integer',
        ];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function classSubject(): BelongsTo
    {
        return $this->belongsTo(ClassSubject::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function splitFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'split_from_series_id');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(self::class, 'split_from_series_id');
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }
}

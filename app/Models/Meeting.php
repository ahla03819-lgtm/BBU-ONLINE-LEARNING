<?php

namespace App\Models;

use App\Enums\MeetingJoinPolicy;
use App\Enums\MeetingStatus;
use Database\Factories\MeetingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['meeting_series_id', 'series_occurrence_on', 'series_sync_version', 'series_override_at', 'uuid', 'school_class_id', 'class_subject_id', 'created_by', 'host_user_id', 'title', 'description', 'scheduled_start_at', 'scheduled_end_at', 'actual_start_at', 'session_started_at', 'actual_end_at', 'status', 'join_policy', 'livekit_room_name', 'max_participants', 'lifecycle_version', 'start_attempt_uuid', 'last_provider_error'])]
class Meeting extends Model
{
    /**
     * A scheduled interval is only trustworthy when both endpoints exist and end
     * after start. Historical rows can hold an inverted or partial interval; those
     * must never be presented to users as a real schedule, and must never be
     * reconstructed from lifecycle timestamps.
     */
    public function hasValidScheduledInterval(): bool
    {
        return $this->scheduled_start_at !== null
            && $this->scheduled_end_at !== null
            && $this->scheduled_end_at->gt($this->scheduled_start_at);
    }

    /** @use HasFactory<MeetingFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected static function booted(): void
    {
        static::creating(function (Meeting $meeting): void {
            $meeting->uuid ??= (string) Str::uuid();
            $meeting->livekit_room_name ??= 'edway_'.Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => MeetingStatus::class,
            'join_policy' => MeetingJoinPolicy::class,
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'actual_start_at' => 'datetime',
            'session_started_at' => 'datetime',
            'actual_end_at' => 'datetime',
            'max_participants' => 'integer',
            'lifecycle_version' => 'integer',
            'series_occurrence_on' => 'date',
            'series_sync_version' => 'integer',
            'series_override_at' => 'datetime',
        ];
    }

    public function meetingSeries(): BelongsTo
    {
        return $this->belongsTo(MeetingSeries::class);
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

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(MeetingJoinRequest::class);
    }

    public function screenShareRequests(): HasMany
    {
        return $this->hasMany(MeetingScreenShareRequest::class);
    }

    public function recordings(): HasMany
    {
        return $this->hasMany(MeetingRecording::class);
    }
}

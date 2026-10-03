<?php

namespace App\Models;

use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['public_uuid', 'meeting_id', 'channel_id', 'started_by_user_id', 'stopped_by_user_id', 'provider', 'provider_egress_id', 'status', 'stop_reason', 'started_at', 'scheduled_stop_at', 'stopped_at', 'ready_at', 'duration_seconds', 'layout', 'provider_output_path', 'storage_disk', 'storage_path', 'mime_type', 'original_name', 'size_bytes', 'failure_reason', 'active_slot'])]
class MeetingRecording extends Model
{
    protected static function booted(): void
    {
        static::creating(function (MeetingRecording $recording): void {
            $recording->public_uuid ??= (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    protected function casts(): array
    {
        return [
            'status' => MeetingRecordingStatus::class,
            'stop_reason' => MeetingRecordingStopReason::class,
            'started_at' => 'datetime',
            'scheduled_stop_at' => 'datetime',
            'stopped_at' => 'datetime',
            'ready_at' => 'datetime',
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
            'active_slot' => 'integer',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function stopper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stopped_by_user_id');
    }

    public function card(): HasOne
    {
        return $this->hasOne(Message::class, 'meeting_recording_id');
    }

    public function isWatchable(): bool
    {
        return $this->status === MeetingRecordingStatus::Ready
            && $this->storage_disk !== null
            && $this->storage_path !== null;
    }

    /**
     * The single active-or-settling recording for a meeting, if there is one.
     *
     * The database guarantees there is at most one; this scope is how callers
     * ask for it without repeating the status list.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public function scopeActiveFor(Builder $query, int $meetingId): Builder
    {
        return $query->where('meeting_id', $meetingId)
            ->whereIn('status', [
                MeetingRecordingStatus::Starting->value,
                MeetingRecordingStatus::Recording->value,
                MeetingRecordingStatus::Stopping->value,
                MeetingRecordingStatus::Processing->value,
            ])
            ->orderByDesc('id');
    }
}
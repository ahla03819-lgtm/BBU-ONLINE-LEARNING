<?php

namespace App\Models;

use App\Enums\MeetingRecordingStatus;
use App\Enums\MeetingRecordingStopReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

#[Fillable(['public_uuid', 'meeting_id', 'channel_id', 'started_by_user_id', 'stopped_by_user_id', 'provider', 'provider_egress_id', 'status', 'stop_reason', 'started_at', 'scheduled_stop_at', 'stopped_at', 'ready_at', 'duration_seconds', 'layout', 'provider_output_path', 'storage_disk', 'storage_path', 'mime_type', 'original_name', 'size_bytes', 'failure_reason', 'active_slot', 'deleted_by', 'deletion_reason'])]
class MeetingRecording extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (MeetingRecording $recording): void {
            $recording->public_uuid ??= (string) Str::uuid();
        });

        static::resolveTrashSchema();
    }

    /**
     * Pre-migration compatibility: when the trash schema columns are absent,
     * drop the SoftDeletes global scope so legacy MeetingRecording queries do
     * not reference the missing deleted_at column.
     *
     * The schema check runs once per process (at model boot) rather than on
     * every query. It is refreshed when PHP workers are restarted after the
     * trash migration is applied and deleted_at exists.
     */
    protected static function resolveTrashSchema(): void
    {
        if (static::$trashSchemaResolved) {
            return;
        }

        static::$trashSchemaAvailable = Schema::hasColumn((new static)->getTable(), 'deleted_at');
        static::$trashSchemaResolved = true;

        if (! static::$trashSchemaAvailable) {
            static::dropSoftDeletesScope();
        }
    }

    protected static function dropSoftDeletesScope(): void
    {
        $scopes = static::getAllGlobalScopes();
        $class = static::class;

        if (! isset($scopes[$class])) {
            return;
        }

        $scopes[$class] = array_values(array_filter($scopes[$class], function ($scope) {
            return ! ($scope instanceof SoftDeletingScope);
        }));

        static::setAllGlobalScopes($scopes);
    }

    public static function trashSchemaAvailable(): bool
    {
        static::resolveTrashSchema();

        return static::$trashSchemaAvailable;
    }

    public static function resetTrashSchemaCache(): void
    {
        static::$trashSchemaResolved = false;
        static::$trashSchemaAvailable = false;
    }

    public static function requireTrashSchema(): void
    {
        static::resolveTrashSchema();

        if (! static::$trashSchemaAvailable) {
            throw new HttpResponseException(
                response()->json(['message' => 'Recording trash is not available on this database.'], 409)
            );
        }
    }

    /**
     * Whether the meeting_recordings table already contains the trash schema
     * columns added by the pending recording-trash migration.
     */
    protected static bool $trashSchemaResolved = false;

    protected static bool $trashSchemaAvailable = false;

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

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
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
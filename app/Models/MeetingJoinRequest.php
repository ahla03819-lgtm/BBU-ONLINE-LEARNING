<?php

namespace App\Models;

use App\Enums\MeetingJoinRequestStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['public_uuid', 'meeting_id', 'requester_user_id', 'status', 'requested_at', 'decided_at', 'decided_by'])]
class MeetingJoinRequest extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (self $request) => $request->public_uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['status' => MeetingJoinRequestStatus::class, 'requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function admitsCurrentEntry(): bool
    {
        return $this->status === MeetingJoinRequestStatus::Admitted
            && $this->decided_at instanceof CarbonInterface;
    }
}

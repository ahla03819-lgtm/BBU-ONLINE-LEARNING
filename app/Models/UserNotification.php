<?php

namespace App\Models;

use Database\Factories\UserNotificationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

#[Fillable(['user_id', 'type', 'actor_id', 'subject_type', 'subject_id', 'deduplication_key', 'context', 'route_name', 'route_parameters', 'payload_version', 'read_at'])]
class UserNotification extends Model
{
    /** @use HasFactory<UserNotificationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (UserNotification $notification) {
            $notification->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'route_parameters' => 'array',
            'payload_version' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

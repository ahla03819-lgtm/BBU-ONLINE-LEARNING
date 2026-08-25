<?php

namespace App\Models;

use App\Enums\AnnouncementStatus;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['channel_id', 'author_id', 'title', 'body', 'status', 'publish_at', 'published_at', 'expires_at', 'pinned_at', 'archived_by', 'archived_at'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => AnnouncementStatus::class, 'publish_at' => 'datetime', 'published_at' => 'datetime', 'expires_at' => 'datetime', 'pinned_at' => 'datetime', 'archived_at' => 'datetime'];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function scopeActiveFeed(Builder $query): Builder
    {
        return $query->where('status', AnnouncementStatus::Published->value)
            ->where('published_at', '<=', now())
            ->where(fn (Builder $expiration) => $expiration->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}

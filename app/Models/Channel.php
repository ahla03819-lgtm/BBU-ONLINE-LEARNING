<?php

namespace App\Models;

use App\Enums\ChannelStatus;
use App\Enums\ChannelType;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['school_class_id', 'class_subject_id', 'name', 'slug', 'description', 'type', 'status', 'default_slot', 'created_by', 'archived_by', 'archived_at'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['type' => ChannelType::class, 'status' => ChannelStatus::class, 'archived_at' => 'datetime'];
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

    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function announcements(): HasMany
    {
        return $this->hasMany(Announcement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ChannelStatus::Active->value);
    }

    public function isDefault(): bool
    {
        return $this->default_slot !== null;
    }
}

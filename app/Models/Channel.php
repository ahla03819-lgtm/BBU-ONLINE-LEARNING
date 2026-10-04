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
use Illuminate\Support\Facades\Storage;

#[Fillable(['school_class_id', 'class_subject_id', 'name', 'slug', 'description', 'type', 'status', 'default_slot', 'created_by', 'archived_by', 'archived_at', 'image_path'])]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    protected $hidden = ['image_path'];

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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function readStates(): HasMany
    {
        return $this->hasMany(ChannelReadState::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ChannelStatus::Active->value);
    }

    public function isDefault(): bool
    {
        return $this->default_slot !== null;
    }

    public function imageUrl(): ?string
    {
        // Mirrors SchoolClass::coverImageUrl(): a managed path with no file on
        // disk resolves to null so the UI shows its fallback icon rather than a
        // broken image.
        if ($this->type !== ChannelType::Custom || ! $this->image_path || ! str_starts_with($this->image_path, "channel-images/{$this->id}/")) {
            return null;
        }

        if (! Storage::disk('public')->exists($this->image_path)) {
            return null;
        }

        return Storage::disk('public')->url($this->image_path);
    }
}

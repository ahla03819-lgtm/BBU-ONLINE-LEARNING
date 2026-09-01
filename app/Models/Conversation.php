<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['type', 'direct_pair_key', 'name', 'created_by_user_id'])]
class Conversation extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(fn (Conversation $conversation) => $conversation->public_uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'public_uuid';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(ConversationCall::class);
    }
}

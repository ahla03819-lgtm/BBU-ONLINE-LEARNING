<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAiNote extends Model
{
    protected $table = 'meeting_ai_notes';

    protected $fillable = [
        'meeting_id',
        'language',
        'content',
        'status',
        'generated_by',
        'provider',
        'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider_metadata' => 'array',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}

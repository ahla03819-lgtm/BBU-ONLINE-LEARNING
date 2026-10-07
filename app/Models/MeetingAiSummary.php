<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingAiSummary extends Model
{
    protected $table = 'meeting_ai_summaries';

    protected $fillable = [
        'meeting_id',
        'language',
        'status',
        'content',
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

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingTranscript extends Model
{
    /** @use HasFactory<MeetingTranscriptFactory> */
    use HasFactory;
    protected $table = 'meeting_transcripts';

    protected $fillable = [
        'meeting_id',
        'speaker_identity',
        'speaker_display_name',
        'original_language',
        'original_text',
        'translated_language',
        'translated_text',
        'started_at',
        'ended_at',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}

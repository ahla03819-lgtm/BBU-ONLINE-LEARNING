<?php

namespace App\Services\AI;

use App\Contracts\TranscriptionProvider;

final class NullTranscriptionProvider implements TranscriptionProvider
{
    public function transcribe(string $audioPayload, array $context = []): array
    {
        return [
            'text' => '',
            'language' => 'en',
            'isFinal' => true,
            'speakerIdentity' => (string) ($context['speakerIdentity'] ?? ''),
            'speakerDisplayName' => (string) ($context['speakerDisplayName'] ?? ''),
        ];
    }
}

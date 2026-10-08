<?php

namespace App\Contracts;

interface TranscriptionProvider
{
    /**
     * @return array{text: string, language: string, isFinal: bool, speakerIdentity: string, speakerDisplayName: string}
     */
    public function transcribe(string $audioPayload, array $context = []): array;
}

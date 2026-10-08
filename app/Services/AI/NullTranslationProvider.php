<?php

namespace App\Services\AI;

use App\Contracts\TranslationProvider;

final class NullTranslationProvider implements TranslationProvider
{
    public function translate(string $text, string $sourceLanguage, string $targetLanguage, array $context = []): array
    {
        return [
            'text' => '',
            'language' => $targetLanguage,
            'sourceLanguage' => $sourceLanguage,
        ];
    }
}

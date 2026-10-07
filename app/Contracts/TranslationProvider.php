<?php

namespace App\Contracts;

interface TranslationProvider
{
    /**
     * @return array{text: string, language: string, sourceLanguage: string}
     */
    public function translate(string $text, string $sourceLanguage, string $targetLanguage, array $context = []): array;
}

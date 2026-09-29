<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the application language preference.
 *
 * English is always the fallback, so an unknown or missing stored value
 * resolves to English instead of rendering an empty interface.
 */
final class Locale
{
    public const DEFAULT = 'en';

    /** @var list<string> */
    public const SUPPORTED = ['en', 'km'];

    /**
     * Coerce any incoming value into a supported locale.
     */
    public static function normalize(mixed $locale): string
    {
        if (! is_string($locale)) {
            return self::DEFAULT;
        }

        $candidate = strtolower(trim($locale));
        $candidate = str_replace('_', '-', $candidate);

        foreach (self::SUPPORTED as $supported) {
            if ($candidate === $supported) {
                return $supported;
            }
        }

        return self::DEFAULT;
    }

    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && in_array(self::normalize($locale), self::SUPPORTED, true)
            && strtolower(trim($locale)) === self::normalize($locale);
    }

    public static function forUser(?User $user): string
    {
        return $user === null ? self::DEFAULT : self::normalize($user->locale);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return ['en' => 'English', 'km' => 'ខ្មែរ'];
    }
}

<?php

namespace App\Services;

use App\Models\SchoolClass;
use RuntimeException;

class ClassJoinCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function normalize(string $code): string
    {
        $value = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return strlen($value) === 6 ? substr($value, 0, 3).'-'.substr($value, 3) : $value;
    }

    public function next(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $this->generate();

            if (! SchoolClass::query()->where('join_code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('A unique class code could not be generated. Please try again.');
    }

    private function generate(): string
    {
        $characters = self::ALPHABET;
        $value = '';

        for ($index = 0; $index < 6; $index++) {
            $value .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return substr($value, 0, 3).'-'.substr($value, 3);
    }
}

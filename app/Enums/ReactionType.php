<?php

namespace App\Enums;

enum ReactionType: string
{
    case Like = 'like';
    case Love = 'love';
    case Laugh = 'laugh';
    case Surprised = 'surprised';
    case Sad = 'sad';
    case Celebrate = 'celebrate';

    public function emoji(): string
    {
        return match ($this) {
            self::Like => '👍', self::Love => '❤️', self::Laugh => '😂',
            self::Surprised => '😮', self::Sad => '😢', self::Celebrate => '🎉',
        };
    }
}

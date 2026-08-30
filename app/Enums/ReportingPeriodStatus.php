<?php

namespace App\Enums;

enum ReportingPeriodStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';

    public function canTransitionTo(self $status): bool
    {
        return match ($this) {
            self::Draft => $status === self::Open,
            self::Open => $status === self::Closed,
            self::Closed => false,
        };
    }
}

<?php

namespace App\Services\LiveKit;

use Carbon\CarbonImmutable;

final readonly class IssuedMeetingToken
{
    public function __construct(public string $token, public CarbonImmutable $expiresAt) {}
}

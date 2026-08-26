<?php

namespace Tests\Fakes;

use App\Services\LiveKit\IssuedMeetingToken;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Carbon\CarbonImmutable;

class FakeLiveKitTokenIssuer implements LiveKitTokenIssuer
{
    public int $calls = 0;

    public function issue(string $roomName, string $identity, string $displayName): IssuedMeetingToken
    {
        $this->calls++;

        return new IssuedMeetingToken('safe-test-token', CarbonImmutable::now()->addMinutes(5));
    }
}

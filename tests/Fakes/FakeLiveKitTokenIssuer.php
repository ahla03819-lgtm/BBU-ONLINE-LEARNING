<?php

namespace Tests\Fakes;

use App\Services\LiveKit\IssuedMeetingToken;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Carbon\CarbonImmutable;

class FakeLiveKitTokenIssuer implements LiveKitTokenIssuer
{
    public int $calls = 0;

    public array $publishSources = [];

    public function issue(string $roomName, string $identity, string $displayName, array $publishSources = ['camera', 'microphone']): IssuedMeetingToken
    {
        $this->calls++;
        $this->publishSources = $publishSources;

        return new IssuedMeetingToken('safe-test-token', CarbonImmutable::now()->addMinutes(5));
    }
}

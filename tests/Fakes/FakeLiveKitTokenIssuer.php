<?php

namespace Tests\Fakes;

use App\Services\LiveKit\IssuedMeetingToken;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Carbon\CarbonImmutable;

class FakeLiveKitTokenIssuer implements LiveKitTokenIssuer
{
    public int $calls = 0;

    public array $publishSources = [];

    public ?string $metadata = null;

    public ?string $roomName = null;

    public ?string $identity = null;

    public ?string $displayName = null;

    public function issue(string $roomName, string $identity, string $displayName, array $publishSources = ['camera', 'microphone'], ?string $metadata = null): IssuedMeetingToken
    {
        $this->calls++;
        $this->roomName = $roomName;
        $this->identity = $identity;
        $this->displayName = $displayName;
        $this->publishSources = $publishSources;
        $this->metadata = $metadata;

        return new IssuedMeetingToken('safe-test-token', CarbonImmutable::now()->addMinutes(5));
    }
}

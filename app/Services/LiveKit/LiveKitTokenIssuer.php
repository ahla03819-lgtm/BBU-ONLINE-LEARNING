<?php

namespace App\Services\LiveKit;

interface LiveKitTokenIssuer
{
    /** @param array<int, string> $publishSources */
    public function issue(string $roomName, string $identity, string $displayName, array $publishSources = ['camera', 'microphone'], ?string $metadata = null, bool $canPublishData = false): IssuedMeetingToken;
}

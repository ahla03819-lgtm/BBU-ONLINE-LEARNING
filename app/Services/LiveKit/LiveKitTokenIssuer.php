<?php

namespace App\Services\LiveKit;

interface LiveKitTokenIssuer
{
    public function issue(string $roomName, string $identity, string $displayName): IssuedMeetingToken;
}

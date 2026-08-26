<?php

namespace App\Services\LiveKit;

use Carbon\CarbonImmutable;

final readonly class VerifiedWebhook
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $roomName,
        public ?string $participantIdentity,
        public ?string $participantSid,
        public CarbonImmutable $occurredAt,
        public string $payloadSha256,
    ) {}
}

<?php

namespace App\Services\LiveKit;

use Carbon\CarbonImmutable;

/**
 * One verified LiveKit webhook.
 *
 * The egress fields are populated only for egress_* events, which is where the
 * provider puts its authoritative EgressInfo record. Nothing in here is ever
 * supplied by the sender unverified: the whole object exists only after the
 * signature and body checksum have been checked against the API secret.
 */
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
        public ?int $trackSource = null,
        public ?string $trackSid = null,
        public ?VerifiedEgress $egress = null,
    ) {}
}
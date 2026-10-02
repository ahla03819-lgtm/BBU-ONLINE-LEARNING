<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\WebhookReceiver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SdkLiveKitWebhookVerifier implements LiveKitWebhookVerifier
{
    public function verify(string $body, string $authorization): VerifiedWebhook
    {
        if (! preg_match('/^Bearer\s+(\S+)$/i', trim($authorization), $matches)) {
            throw new InvalidArgumentException('Malformed LiveKit webhook authorization header.');
        }
        $event = (new WebhookReceiver(config('livekit.api_key'), config('livekit.api_secret')))
            ->receive($body, $matches[1]);
        $room = $event->getRoom();
        $participant = $event->getParticipant();
        $track = $event->getTrack();

        return new VerifiedWebhook(
            $event->getId(), $event->getEvent(), $room?->getName(),
            $participant?->getIdentity(), $participant?->getSid(),
            CarbonImmutable::createFromTimestamp((int) $event->getCreatedAt()), hash('sha256', $body),
            $track?->getSource(), $track?->getSid() ?: null,
        );
    }
}

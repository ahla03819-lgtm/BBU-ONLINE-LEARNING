<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\WebhookReceiver;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livekit\EgressInfo;

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
        $egressInfo = $event->getEgressInfo();

        return new VerifiedWebhook(
            $event->getId(), $event->getEvent(), $room?->getName(),
            $participant?->getIdentity(), $participant?->getSid(),
            CarbonImmutable::createFromTimestamp((int) $event->getCreatedAt()), hash('sha256', $body),
            $track?->getSource(), $track?->getSid() ?: null,
            // Egress lifecycle events carry the provider's own EgressInfo. Reading it
            // here, after the signature check, is what lets a recording be finalised
            // from the provider's word alone instead of from anything a client says.
            $egressInfo ? $this->egress($egressInfo) : null,
        );
    }

    private function egress(EgressInfo $info): ?VerifiedEgress
    {
        $egressId = (string) $info->getEgressId();
        if ($egressId === '') {
            return null;
        }
        $startedAt = (int) $info->getStartedAt();
        $endedAt = (int) $info->getEndedAt();

        return new VerifiedEgress(
            $egressId,
            EgressInfoMapper::status($info),
            EgressInfoMapper::files($info),
            $startedAt > 0 ? CarbonImmutable::createFromTimestamp($startedAt) : null,
            $endedAt > 0 ? CarbonImmutable::createFromTimestamp($endedAt) : null,
            $info->getError() ?: null,
        );
    }
}
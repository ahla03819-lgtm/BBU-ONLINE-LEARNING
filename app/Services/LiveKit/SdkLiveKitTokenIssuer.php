<?php

namespace App\Services\LiveKit;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\VideoGrant;
use Carbon\CarbonImmutable;
use Livekit\TrackSource;
use RuntimeException;

final class SdkLiveKitTokenIssuer implements LiveKitTokenIssuer
{
    public function issue(string $roomName, string $identity, string $displayName): IssuedMeetingToken
    {
        $key = config('livekit.api_key');
        $secret = config('livekit.api_secret');
        if (! is_string($key) || $key === '' || ! is_string($secret) || $secret === '') {
            throw new RuntimeException('LiveKit token service is not configured.');
        }

        $ttl = (int) config('livekit.token_ttl_seconds', 300);
        $options = (new AccessTokenOptions)->setIdentity($identity)->setName($displayName)->setTtl($ttl);
        $grant = (new VideoGrant)
            ->setRoomJoin(true)->setRoomName($roomName)
            ->setCanPublish(true)->setCanSubscribe(true)->setCanPublishData(false)
            ->setCanPublishSources([TrackSource::CAMERA, TrackSource::MICROPHONE]);
        $token = (new AccessToken($key, $secret))->init($options)->setGrant($grant)->toJwt();

        return new IssuedMeetingToken($token, CarbonImmutable::now()->addSeconds($ttl));
    }
}

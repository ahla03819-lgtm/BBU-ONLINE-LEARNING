<?php

namespace Tests\Unit;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\VideoGrant;
use App\Services\LiveKit\SdkLiveKitTokenIssuer;
use App\Services\LiveKit\SdkLiveKitWebhookVerifier;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Tests\TestCase;

class LiveKitSdkContractTest extends TestCase
{
    public function test_token_adapter_issues_exact_room_limited_media_grants_for_five_minutes(): void
    {
        config(['livekit.api_key' => 'test-key', 'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes', 'livekit.token_ttl_seconds' => 300]);
        CarbonImmutable::setTestNow('2026-08-26 12:00:00');

        $issued = app(SdkLiveKitTokenIssuer::class)->issue('room-safe', 'opaque-id', 'Student Name');
        $payload = (array) JWT::decode($issued->token, new Key('test-secret-that-is-at-least-32-bytes', 'HS256'));
        $video = (array) $payload['video'];

        $this->assertSame('opaque-id', $payload['sub']);
        $this->assertSame(300, $payload['exp'] - $payload['iat']);
        $this->assertSame('room-safe', $video['room']);
        $this->assertTrue($video['roomJoin']);
        $this->assertTrue($video['canPublish']);
        $this->assertTrue($video['canSubscribe']);
        $this->assertFalse($video['canPublishData']);
        $this->assertSame(['camera', 'microphone'], $video['canPublishSources']);
        $this->assertNotSame([1, 2], $video['canPublishSources']);
        $this->assertArrayNotHasKey('roomAdmin', $video);
        $this->assertSame('2026-08-26T12:05:00+00:00', $issued->expiresAt->toIso8601String());
        CarbonImmutable::setTestNow();
    }

    public function test_webhook_adapter_accepts_valid_signature_and_rejects_tampering(): void
    {
        config(['livekit.api_key' => 'test-key', 'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes']);
        $body = json_encode([
            'event' => 'participant_joined', 'id' => 'event-uuid', 'createdAt' => 1_788_000_000,
            'room' => ['name' => 'room-safe'],
            'participant' => ['identity' => 'opaque-id', 'sid' => 'PA_safe'],
        ], JSON_THROW_ON_ERROR);
        $authorization = (new AccessToken('test-key', 'test-secret-that-is-at-least-32-bytes'))
            ->setGrant(new VideoGrant)->setSha256(base64_encode(hash('sha256', $body, true)))->toJwt();

        $verified = app(SdkLiveKitWebhookVerifier::class)->verify($body, 'Bearer '.$authorization);
        $this->assertSame('event-uuid', $verified->eventId);
        $this->assertSame('participant_joined', $verified->eventType);
        $this->assertSame('room-safe', $verified->roomName);
        $this->assertSame('opaque-id', $verified->participantIdentity);
        $this->assertSame(hash('sha256', $body), $verified->payloadSha256);

        $this->expectException(\Exception::class);
        app(SdkLiveKitWebhookVerifier::class)->verify($body.' ', 'Bearer '.$authorization);
    }

    public function test_token_adapter_limits_screen_share_to_explicit_publish_sources(): void
    {
        config(['livekit.api_key' => 'test-key', 'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes']);

        $issued = app(SdkLiveKitTokenIssuer::class)->issue('room-safe', 'opaque-id', 'Teacher Name', ['camera', 'microphone', 'screen_share', 'screen_share_audio']);
        $payload = (array) JWT::decode($issued->token, new Key('test-secret-that-is-at-least-32-bytes', 'HS256'));
        $video = (array) $payload['video'];

        $this->assertSame(['camera', 'microphone', 'screen_share', 'screen_share_audio'], $video['canPublishSources']);
    }

    public function test_webhook_adapter_rejects_malformed_authorization_header(): void
    {
        config(['livekit.api_key' => 'test-key', 'livekit.api_secret' => 'test-secret-that-is-at-least-32-bytes']);
        $this->expectException(\InvalidArgumentException::class);
        app(SdkLiveKitWebhookVerifier::class)->verify('{}', 'not-a-bearer-header');
    }
}

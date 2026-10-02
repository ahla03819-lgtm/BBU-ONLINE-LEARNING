<?php

namespace Tests\Unit;

use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\MeetingProviderState;
use App\Services\LiveKit\SdkLiveKitRoomManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Livekit\DeleteRoomResponse;
use Livekit\ListRoomsResponse;
use Livekit\MuteRoomTrackResponse;
use Livekit\ParticipantInfo;
use Livekit\ParticipantPermission;
use Livekit\RemoveParticipantResponse;
use Livekit\Room;
use Livekit\TrackInfo;
use Livekit\TrackSource;
use Livekit\TrackType;
use Livekit\TwirpError;
use Mockery;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Twirp\ErrorCode;

class LiveKitRoomManagerContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'livekit.url' => 'wss://public.example.test',
            'livekit.api_url' => 'https://provider.example.test',
            'livekit.api_key' => 'key',
            'livekit.api_secret' => 'secret',
        ]);
    }

    public function test_backend_client_uses_only_the_https_api_url(): void
    {
        $manager = new SdkLiveKitRoomManager;
        $managerReflection = new ReflectionClass($manager);
        $clientMethod = $managerReflection->getMethod('client');
        $client = $clientMethod->invoke($manager);
        $clientReflection = new ReflectionClass($client);
        $host = $clientReflection->getParentClass()->getProperty('host');

        $this->assertSame('https://provider.example.test', $host->getValue($client));
        $this->assertNotSame(config('livekit.url'), $host->getValue($client));
    }

    public function test_invalid_backend_scheme_fails_safely_without_calling_the_sdk(): void
    {
        config(['livekit.api_url' => 'wss://provider.example.test']);
        Log::shouldReceive('warning')->once()->with(
            'LiveKit Room Service is not safely configured.',
            ['operation' => 'create'],
        );
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldNotReceive('createRoom');

        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($client))->create('opaque-room', 42));
    }

    public function test_create_passes_opaque_name_and_capacity_and_success_is_active(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('createRoom')->once()->with(Mockery::on(fn (RoomCreateOptions $options) => $options->getName() === 'opaque-room' && $options->getMaxParticipants() === 42))->andReturn(new Room);
        $this->assertSame(MeetingProviderState::Active, (new SdkLiveKitRoomManager($client))->create('opaque-room', 42));
    }

    public function test_existing_room_is_canonical_create_success(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('createRoom')->andThrow($this->twirpError(ErrorCode::AlreadyExists));
        $this->assertSame(MeetingProviderState::Active, (new SdkLiveKitRoomManager($client))->create('opaque-room', 42));
    }

    public function test_definitive_and_unknown_create_failures_are_distinguished(): void
    {
        $definitive = Mockery::mock(RoomServiceClient::class);
        $definitive->shouldReceive('createRoom')->andThrow($this->twirpError(ErrorCode::PermissionDenied));
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($definitive))->create('opaque-room', 42));

        $unknown = Mockery::mock(RoomServiceClient::class);
        $unknown->shouldReceive('createRoom')->andThrow(new RuntimeException('transport detail'));
        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($unknown))->create('opaque-room', 42));
    }

    public function test_delete_success_and_absent_room_are_canonical_ended(): void
    {
        $success = Mockery::mock(RoomServiceClient::class);
        $success->shouldReceive('deleteRoom')->once()->with('opaque-room')->andReturn(new DeleteRoomResponse);
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($success))->delete('opaque-room'));

        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('deleteRoom')->andThrow($this->twirpError(ErrorCode::NotFound));
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($absent))->delete('opaque-room'));
    }

    public function test_inspection_maps_present_absent_and_provider_error(): void
    {
        $present = Mockery::mock(RoomServiceClient::class);
        $present->shouldReceive('listRooms')->with(['opaque-room'])->andReturn(new ListRoomsResponse(['rooms' => [new Room(['name' => 'opaque-room'])]]));
        $this->assertSame(MeetingProviderState::Active, (new SdkLiveKitRoomManager($present))->inspect('opaque-room'));

        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('listRooms')->andReturn(new ListRoomsResponse);
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($absent))->inspect('opaque-room'));

        $unknown = Mockery::mock(RoomServiceClient::class);
        $unknown->shouldReceive('listRooms')->andThrow(new RuntimeException('transport detail'));
        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($unknown))->inspect('opaque-room'));
    }

    public function test_participant_removal_maps_success_absence_and_unknown_safely(): void
    {
        $success = Mockery::mock(RoomServiceClient::class);
        $success->shouldReceive('removeParticipant')->once()->with('opaque-room', 'opaque-identity')->andReturn(new RemoveParticipantResponse);
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($success))->removeParticipant('opaque-room', 'opaque-identity'));

        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('removeParticipant')->andThrow($this->twirpError(ErrorCode::NotFound));
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($absent))->removeParticipant('opaque-room', 'opaque-identity'));

        $unknown = Mockery::mock(RoomServiceClient::class);
        $unknown->shouldReceive('removeParticipant')->andThrow(new RuntimeException('transport detail'));
        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($unknown))->removeParticipant('opaque-room', 'opaque-identity'));
    }

    public function test_microphone_moderation_resolves_the_provider_track_and_only_mutes(): void
    {
        $participant = new ParticipantInfo(['tracks' => [
            new TrackInfo(['sid' => 'camera-track', 'type' => TrackType::VIDEO, 'source' => TrackSource::CAMERA]),
            new TrackInfo(['sid' => 'microphone-track', 'type' => TrackType::AUDIO, 'source' => TrackSource::MICROPHONE, 'muted' => false]),
        ]]);
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->with('opaque-room', 'opaque-identity')->andReturn($participant);
        $client->shouldReceive('mutePublishedTrack')->once()->with('opaque-room', 'opaque-identity', 'microphone-track', true)->andReturn(new MuteRoomTrackResponse);

        $this->assertSame(MeetingMicrophoneMuteResult::Muted, (new SdkLiveKitRoomManager($client))->muteParticipantMicrophone('opaque-room', 'opaque-identity'));
    }

    public function test_microphone_moderation_handles_muted_missing_absent_and_provider_failure(): void
    {
        $alreadyMuted = Mockery::mock(RoomServiceClient::class);
        $alreadyMuted->shouldReceive('getParticipant')->andReturn(new ParticipantInfo(['tracks' => [
            new TrackInfo(['sid' => 'microphone-track', 'type' => TrackType::AUDIO, 'source' => TrackSource::MICROPHONE, 'muted' => true]),
        ]]));
        $alreadyMuted->shouldNotReceive('mutePublishedTrack');
        $this->assertSame(MeetingMicrophoneMuteResult::AlreadyMuted, (new SdkLiveKitRoomManager($alreadyMuted))->muteParticipantMicrophone('opaque-room', 'opaque-identity'));

        $missing = Mockery::mock(RoomServiceClient::class);
        $missing->shouldReceive('getParticipant')->andReturn(new ParticipantInfo(['tracks' => [
            new TrackInfo(['sid' => 'screen-audio', 'type' => TrackType::AUDIO, 'source' => TrackSource::SCREEN_SHARE_AUDIO]),
        ]]));
        $missing->shouldNotReceive('mutePublishedTrack');
        $this->assertSame(MeetingMicrophoneMuteResult::NoActiveMicrophone, (new SdkLiveKitRoomManager($missing))->muteParticipantMicrophone('opaque-room', 'opaque-identity'));

        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('getParticipant')->andThrow($this->twirpError(ErrorCode::NotFound));
        $this->assertSame(MeetingMicrophoneMuteResult::ParticipantNotPresent, (new SdkLiveKitRoomManager($absent))->muteParticipantMicrophone('opaque-room', 'opaque-identity'));

        $failed = Mockery::mock(RoomServiceClient::class);
        $failed->shouldReceive('getParticipant')->andThrow(new RuntimeException('provider transport detail'));
        $this->assertSame(MeetingMicrophoneMuteResult::ProviderFailure, (new SdkLiveKitRoomManager($failed))->muteParticipantMicrophone('opaque-room', 'opaque-identity'));
    }

    public function test_screen_share_permission_changes_only_screen_sources_and_preserves_normal_media(): void
    {
        $current = new ParticipantPermission([
            'can_subscribe' => true,
            'can_publish' => true,
            'can_publish_data' => true,
            'can_publish_sources' => [TrackSource::CAMERA, TrackSource::MICROPHONE],
            'can_update_metadata' => false,
        ]);
        $participant = new ParticipantInfo(['permission' => $current]);
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->twice()->with('opaque-room', 'server-identity')->andReturn($participant);
        $client->shouldReceive('updateParticipant')->once()->with(
            'opaque-room',
            'server-identity',
            null,
            Mockery::on(fn (ParticipantPermission $permission) => $permission->getCanPublish()
                && $permission->getCanPublishData()
                && iterator_to_array($permission->getCanPublishSources()) === [
                    TrackSource::CAMERA,
                    TrackSource::MICROPHONE,
                    TrackSource::SCREEN_SHARE,
                    TrackSource::SCREEN_SHARE_AUDIO,
                ]),
        )->andReturn(new ParticipantInfo);
        $client->shouldReceive('updateParticipant')->once()->with(
            'opaque-room',
            'server-identity',
            null,
            Mockery::on(fn (ParticipantPermission $permission) => $permission->getCanPublish()
                && $permission->getCanPublishData()
                && iterator_to_array($permission->getCanPublishSources()) === [TrackSource::CAMERA, TrackSource::MICROPHONE]),
        )->andReturn(new ParticipantInfo);

        $manager = new SdkLiveKitRoomManager($client);
        $this->assertSame(MeetingProviderState::Active, $manager->setParticipantScreenSharePermission('opaque-room', 'server-identity', true));
        $this->assertSame(MeetingProviderState::Active, $manager->setParticipantScreenSharePermission('opaque-room', 'server-identity', false));
    }

    public function test_published_track_mute_is_one_way_and_maps_absent_and_provider_failures_safely(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        // Moderation never unmutes: the mute flag is always sent as true.
        $client->shouldReceive('mutePublishedTrack')->once()
            ->with('opaque-room', 'server-identity', 'TR_verified', true)
            ->andReturn(new MuteRoomTrackResponse);
        $this->assertSame(MeetingProviderState::Active, (new SdkLiveKitRoomManager($client))->mutePublishedTrack('opaque-room', 'server-identity', 'TR_verified'));

        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('mutePublishedTrack')->andThrow($this->twirpError(ErrorCode::NotFound));
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($absent))->mutePublishedTrack('opaque-room', 'opaque-identity', 'TR_gone'));

        $failed = Mockery::mock(RoomServiceClient::class);
        $failed->shouldReceive('mutePublishedTrack')->andThrow(new RuntimeException('provider transport detail'));
        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($failed))->mutePublishedTrack('opaque-room', 'opaque-identity', 'TR_sid'));
    }

    public function test_screen_share_permission_maps_absent_and_provider_failures_safely(): void
    {
        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('getParticipant')->andThrow($this->twirpError(ErrorCode::NotFound));
        $this->assertSame(MeetingProviderState::Ended, (new SdkLiveKitRoomManager($absent))->setParticipantScreenSharePermission('opaque-room', 'opaque-identity', false));

        $failed = Mockery::mock(RoomServiceClient::class);
        $failed->shouldReceive('getParticipant')->andThrow(new RuntimeException('provider transport detail'));
        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($failed))->setParticipantScreenSharePermission('opaque-room', 'opaque-identity', true));
    }

    /**
     * Builds a real Livekit\TrackInfo protobuf record.
     */
    private function track(string $sid, int $source, int $type): TrackInfo
    {
        return new TrackInfo(['sid' => $sid, 'source' => $source, 'type' => $type]);
    }

    public function test_screen_share_state_uses_the_exact_room_and_identity_and_ignores_camera_and_microphone(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->with('opaque-room', 'server-identity')->andReturn(new ParticipantInfo([
            'sid' => 'PA_publisher',
            'tracks' => [
                $this->track('TR_camera', TrackSource::CAMERA, TrackType::VIDEO),
                $this->track('TR_mic', TrackSource::MICROPHONE, TrackType::AUDIO),
            ],
        ]));

        $state = (new SdkLiveKitRoomManager($client))->screenShareState('opaque-room', 'server-identity');

        $this->assertSame(MeetingProviderState::Active, $state->state);
        $this->assertNull($state->videoTrackSid);
        $this->assertSame([], $state->audioTrackSids);
        $this->assertFalse($state->isSharing());
    }

    public function test_screen_share_state_reports_only_a_screen_share_video_track_as_canonical(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->andReturn(new ParticipantInfo([
            'sid' => 'PA_publisher',
            'tracks' => [
                $this->track('TR_camera', TrackSource::CAMERA, TrackType::VIDEO),
                $this->track('TR_mic', TrackSource::MICROPHONE, TrackType::AUDIO),
                $this->track('TR_screen_video', TrackSource::SCREEN_SHARE, TrackType::VIDEO),
                $this->track('TR_screen_audio', TrackSource::SCREEN_SHARE_AUDIO, TrackType::AUDIO),
            ],
        ]));

        $state = (new SdkLiveKitRoomManager($client))->screenShareState('opaque-room', 'server-identity');

        $this->assertSame(MeetingProviderState::Active, $state->state);
        $this->assertSame('TR_screen_video', $state->videoTrackSid);
        $this->assertSame(['TR_screen_audio'], $state->audioTrackSids);
        $this->assertTrue($state->isSharing());
    }

    public function test_screen_share_audio_alone_is_never_treated_as_a_started_share(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->andReturn(new ParticipantInfo([
            'sid' => 'PA_publisher',
            'tracks' => [$this->track('TR_screen_audio', TrackSource::SCREEN_SHARE_AUDIO, TrackType::AUDIO)],
        ]));

        $state = (new SdkLiveKitRoomManager($client))->screenShareState('opaque-room', 'server-identity');

        $this->assertSame(MeetingProviderState::Active, $state->state);
        $this->assertNull($state->videoTrackSid);
        $this->assertSame(['TR_screen_audio'], $state->audioTrackSids);
        $this->assertFalse($state->isSharing());
    }

    public function test_a_screen_share_source_without_a_video_track_is_not_canonical(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->andReturn(new ParticipantInfo([
            'sid' => 'PA_publisher',
            'tracks' => [$this->track('TR_not_video', TrackSource::SCREEN_SHARE, TrackType::AUDIO)],
        ]));

        $this->assertFalse((new SdkLiveKitRoomManager($client))->screenShareState('opaque-room', 'server-identity')->isSharing());
    }

    public function test_screen_share_state_distinguishes_absence_from_provider_failure(): void
    {
        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('getParticipant')->andThrow($this->twirpError(ErrorCode::NotFound));
        $ended = (new SdkLiveKitRoomManager($absent))->screenShareState('opaque-room', 'server-identity');
        $this->assertSame(MeetingProviderState::Ended, $ended->state);
        $this->assertNull($ended->videoTrackSid);
        $this->assertFalse($ended->isSharing());

        $failed = Mockery::mock(RoomServiceClient::class);
        $failed->shouldReceive('getParticipant')->andThrow(new RuntimeException('provider transport detail'));
        $unknown = (new SdkLiveKitRoomManager($failed))->screenShareState('opaque-room', 'server-identity');
        $this->assertSame(MeetingProviderState::Unknown, $unknown->state);
        $this->assertFalse($unknown->isSharing());
    }

    public function test_screen_share_state_fails_closed_when_the_backend_is_not_safely_configured(): void
    {
        config(['livekit.api_url' => 'wss://provider.example.test']);
        Log::shouldReceive('warning')->once()->with(
            'LiveKit Room Service operation failed.',
            Mockery::on(fn (array $context) => $context === [
                'operation' => 'screen_share_state',
                'exception_class' => RuntimeException::class,
                'provider_code' => null,
            ]),
        );
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldNotReceive('getParticipant');

        $state = (new SdkLiveKitRoomManager($client))->screenShareState('opaque-room', 'server-identity');

        $this->assertSame(MeetingProviderState::Unknown, $state->state);
        $this->assertNull($state->videoTrackSid);
        $this->assertFalse($state->isSharing());
    }

    public function test_participant_presence_looks_up_the_exact_room_and_identity_and_prefers_millisecond_join_time(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->with('opaque-room', 'server-identity')->andReturn(new ParticipantInfo([
            'sid' => 'PA_current',
            'joined_at' => 1_700_000_000,
            'joined_at_ms' => 1_700_000_123_456,
        ]));

        $presence = (new SdkLiveKitRoomManager($client))->participantPresence('opaque-room', 'server-identity');

        $this->assertSame(MeetingProviderState::Active, $presence->state);
        $this->assertSame('PA_current', $presence->participantSid);
        $this->assertTrue($presence->isPresent());
        // joined_at_ms is the authoritative millisecond field; the seconds field
        // must not be used while it is populated.
        $this->assertSame(
            CarbonImmutable::createFromTimestampMs(1_700_000_123_456)->getTimestampMs(),
            $presence->joinedAt?->getTimestampMs(),
        );
    }

    public function test_participant_presence_falls_back_to_seconds_when_milliseconds_are_unavailable(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->andReturn(new ParticipantInfo([
            'sid' => 'PA_seconds',
            'joined_at' => 1_700_000_000,
            'joined_at_ms' => 0,
        ]));

        $presence = (new SdkLiveKitRoomManager($client))->participantPresence('opaque-room', 'server-identity');

        $this->assertSame(MeetingProviderState::Active, $presence->state);
        $this->assertSame(1_700_000_000_000, $presence->joinedAt?->getTimestampMs());
    }

    public function test_participant_presence_without_any_join_time_stays_present_with_no_joined_at(): void
    {
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('getParticipant')->once()->andReturn(new ParticipantInfo(['sid' => 'PA_untimed']));

        $presence = (new SdkLiveKitRoomManager($client))->participantPresence('opaque-room', 'server-identity');

        $this->assertSame(MeetingProviderState::Active, $presence->state);
        $this->assertSame('PA_untimed', $presence->participantSid);
        $this->assertNull($presence->joinedAt);
    }

    public function test_participant_presence_maps_absence_and_failures_without_inventing_a_participant(): void
    {
        $absent = Mockery::mock(RoomServiceClient::class);
        $absent->shouldReceive('getParticipant')->andThrow($this->twirpError(ErrorCode::NotFound));
        $ended = (new SdkLiveKitRoomManager($absent))->participantPresence('opaque-room', 'server-identity');
        $this->assertSame(MeetingProviderState::Ended, $ended->state);
        $this->assertNull($ended->participantSid);
        $this->assertFalse($ended->isPresent());

        $failed = Mockery::mock(RoomServiceClient::class);
        $failed->shouldReceive('getParticipant')->andThrow(new RuntimeException('provider transport detail'));
        $unknown = (new SdkLiveKitRoomManager($failed))->participantPresence('opaque-room', 'server-identity');
        $this->assertSame(MeetingProviderState::Unknown, $unknown->state);
        $this->assertNull($unknown->participantSid);
        $this->assertFalse($unknown->isPresent());

        // A provider that answers with a present participant but no SID cannot be
        // treated as proof of presence, so it must fail closed too.
        $sidless = Mockery::mock(RoomServiceClient::class);
        $sidless->shouldReceive('getParticipant')->andReturn(new ParticipantInfo(['joined_at' => 1_700_000_000]));
        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($sidless))->participantPresence('opaque-room', 'server-identity')->state);
    }

    public function test_participant_presence_fails_closed_when_the_backend_is_not_safely_configured(): void
    {
        config(['livekit.api_url' => 'wss://provider.example.test']);
        Log::shouldReceive('warning')->once()->with(
            'LiveKit Room Service operation failed.',
            Mockery::on(fn (array $context) => $context === [
                'operation' => 'participant_presence',
                'exception_class' => RuntimeException::class,
                'provider_code' => null,
            ]),
        );
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldNotReceive('getParticipant');

        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($client))->participantPresence('opaque-room', 'server-identity')->state);
    }

    public function test_transport_failure_logging_never_contains_provider_details(): void
    {
        Log::shouldReceive('warning')->once()->with(
            'LiveKit Room Service operation failed.',
            Mockery::on(fn (array $context) => $context === [
                'operation' => 'inspect',
                'exception_class' => RuntimeException::class,
                'provider_code' => null,
            ]),
        );
        $client = Mockery::mock(RoomServiceClient::class);
        $client->shouldReceive('listRooms')->andThrow(new RuntimeException('secret technical-room authorization-header'));

        $this->assertSame(MeetingProviderState::Unknown, (new SdkLiveKitRoomManager($client))->inspect('technical-room'));
    }

    public function test_environment_example_contains_placeholders_without_credentials(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('LIVEKIT_URL=wss://your-project.livekit.cloud', $example);
        $this->assertStringContainsString('LIVEKIT_API_URL=https://your-project.livekit.cloud', $example);
        $this->assertMatchesRegularExpression('/^LIVEKIT_API_KEY=$/m', $example);
        $this->assertMatchesRegularExpression('/^LIVEKIT_API_SECRET=$/m', $example);
    }

    private function twirpError(string $code): TwirpError
    {
        return new TwirpError($code, 'sanitized test error');
    }
}

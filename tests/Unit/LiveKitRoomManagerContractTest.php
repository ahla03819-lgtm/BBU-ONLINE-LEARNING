<?php

namespace Tests\Unit;

use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\MeetingProviderState;
use App\Services\LiveKit\SdkLiveKitRoomManager;
use Illuminate\Support\Facades\Log;
use Livekit\DeleteRoomResponse;
use Livekit\ListRoomsResponse;
use Livekit\MuteRoomTrackResponse;
use Livekit\ParticipantInfo;
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

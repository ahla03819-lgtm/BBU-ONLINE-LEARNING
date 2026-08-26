<?php

namespace Tests\Unit;

use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use App\Enums\MeetingProviderState;
use App\Services\LiveKit\SdkLiveKitRoomManager;
use Livekit\DeleteRoomResponse;
use Livekit\ListRoomsResponse;
use Livekit\Room;
use Livekit\TwirpError;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Twirp\ErrorCode;

class LiveKitRoomManagerContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['livekit.url' => 'https://provider.test', 'livekit.api_key' => 'key', 'livekit.api_secret' => 'secret']);
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

    private function twirpError(string $code): TwirpError
    {
        return new TwirpError($code, 'sanitized test error');
    }
}

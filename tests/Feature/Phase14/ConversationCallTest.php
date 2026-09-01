<?php

namespace Tests\Feature\Phase14;

use App\Events\ConversationCallSignal;
use App\Models\AcademicYear;
use App\Models\Conversation;
use App\Models\ConversationCall;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\LiveKit\LiveKitTokenIssuer;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Fakes\FakeLiveKitTokenIssuer;
use Tests\TestCase;

class ConversationCallTest extends TestCase
{
    use RefreshDatabase;

    private FakeLiveKitTokenIssuer $issuer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->issuer = new FakeLiveKitTokenIssuer;
        $this->app->instance(LiveKitTokenIssuer::class, $this->issuer);
        config(['livekit.url' => 'wss://public.example.test']);
    }

    public function test_direct_members_can_start_accept_and_issue_a_server_owned_token(): void
    {
        [$a, $b, $conversation] = $this->conversation();
        Event::fake([ConversationCallSignal::class]);
        $call = $this->actingAs($a)->postJson(route('conversation-calls.store', $conversation), ['type' => 'video'])->assertCreated()->json('call');
        $this->assertSame('ringing', $call['status']);
        $model = ConversationCall::where('public_uuid', $call['uuid'])->firstOrFail();
        $this->actingAs($b)->postJson(route('conversation-calls.respond', $model), ['decision' => 'accepted'])->assertOk();
        $this->assertDatabaseHas('conversation_calls', ['id' => $model->id, 'status' => 'active']);
        $this->actingAs($b)->postJson(route('conversation-calls.token', $model))
            ->assertOk()
            ->assertJsonPath('token', 'safe-test-token')
            ->assertJsonPath('server_url', 'wss://public.example.test')
            ->assertJsonPath('identity', 'conversation-call:'.$model->public_uuid.':'.$b->id);
        $this->assertSame($model->livekit_room_name, $this->issuer->roomName);
        $this->assertSame('conversation-call:'.$model->public_uuid.':'.$b->id, $this->issuer->identity);
        $this->assertSame(['camera', 'microphone', 'screen_share', 'screen_share_audio'], $this->issuer->publishSources);
        Event::assertDispatched(ConversationCallSignal::class, fn ($event) => $event->broadcastAs() === 'conversation.call.started');
    }

    public function test_non_member_and_non_member_super_admin_cannot_start_view_or_token_a_private_call(): void
    {
        [$a, , $conversation] = $this->conversation();
        $outside = $this->user('Student');
        $admin = $this->user('Super Admin');
        $call = $this->actingAs($a)->postJson(route('conversation-calls.store', $conversation), ['type' => 'audio'])->json('call');
        $model = ConversationCall::where('public_uuid', $call['uuid'])->firstOrFail();
        $this->actingAs($outside)->postJson(route('conversation-calls.token', $model))->assertForbidden();
        $this->actingAs($admin)->get(route('conversation-calls.room', $model))->assertForbidden();
    }

    public function test_caller_can_cancel_ringing_call_and_tokens_are_denied_afterwards(): void
    {
        [$a, $b, $conversation] = $this->conversation();
        $call = $this->actingAs($a)->postJson(route('conversation-calls.store', $conversation), ['type' => 'audio'])->json('call');
        $model = ConversationCall::where('public_uuid', $call['uuid'])->firstOrFail();
        $this->actingAs($a)->postJson(route('conversation-calls.cancel', $model))->assertOk();
        $this->actingAs($b)->postJson(route('conversation-calls.token', $model))->assertForbidden();
    }

    public function test_only_the_other_direct_member_can_accept_or_decline_a_ringing_call(): void
    {
        [$a, $b, $conversation] = $this->conversation();
        $call = $this->actingAs($a)->postJson(route('conversation-calls.store', $conversation), ['type' => 'audio'])->json('call');
        $model = ConversationCall::where('public_uuid', $call['uuid'])->firstOrFail();

        $this->actingAs($a)->postJson(route('conversation-calls.respond', $model), ['decision' => 'accepted'])->assertForbidden();
        $this->actingAs($b)->postJson(route('conversation-calls.respond', $model), ['decision' => 'declined'])->assertOk();

        $this->assertDatabaseHas('conversation_calls', ['id' => $model->id, 'status' => 'declined']);
        $this->assertDatabaseHas('conversation_call_participants', ['conversation_call_id' => $model->id, 'user_id' => $b->id]);
        $this->assertNotNull($model->fresh()->participants()->where('user_id', $b->id)->value('declined_at'));
    }

    public function test_each_call_receives_a_unique_server_generated_livekit_room(): void
    {
        [$a, $b, $conversation] = $this->conversation();
        $first = $this->actingAs($a)->postJson(route('conversation-calls.store', $conversation), ['type' => 'audio'])->json('call');
        $firstModel = ConversationCall::where('public_uuid', $first['uuid'])->firstOrFail();
        $this->actingAs($a)->postJson(route('conversation-calls.cancel', $firstModel))->assertOk();
        $second = $this->actingAs($a)->postJson(route('conversation-calls.store', $conversation), ['type' => 'video'])->json('call');
        $secondModel = ConversationCall::where('public_uuid', $second['uuid'])->firstOrFail();

        $this->assertNotSame($firstModel->livekit_room_name, $secondModel->livekit_room_name);
        $this->assertStringStartsWith('bbu_call_', $secondModel->livekit_room_name);
        $this->assertSame(49, strlen($secondModel->livekit_room_name));
        $this->assertNotSame($conversation->public_uuid, $secondModel->livekit_room_name);
    }

    public function test_group_call_is_active_for_members_and_leaving_one_member_does_not_end_it(): void
    {
        [$a, $b, $conversation] = $this->conversation();
        $conversation->update(['type' => 'group', 'name' => 'Study group']);
        $call = $this->actingAs($a)->postJson(route('conversation-calls.store', $conversation), ['type' => 'audio'])->assertCreated()->json('call');
        $model = ConversationCall::where('public_uuid', $call['uuid'])->firstOrFail();
        $this->assertSame('active', $model->status);
        $this->actingAs($b)->postJson(route('conversation-calls.token', $model))
            ->assertOk()
            ->assertJsonPath('token', 'safe-test-token');
        $this->assertSame($model->livekit_room_name, $this->issuer->roomName);
        $this->actingAs($b)->postJson(route('conversation-calls.leave', $model))->assertOk();
        $this->assertSame('active', $model->fresh()->status);
    }

    private function conversation(): array
    {
        $year = AcademicYear::factory()->active()->create();
        $class = SchoolClass::factory()->create(['academic_year_id' => $year->id]);
        $a = $this->member($class);
        $b = $this->member($class);
        $conversation = Conversation::create(['type' => 'direct', 'direct_pair_key' => collect([$a->id, $b->id])->sort()->implode(':'), 'created_by_user_id' => $a->id]);
        $conversation->members()->createMany([['user_id' => $a->id, 'role' => 'member', 'joined_at' => now()], ['user_id' => $b->id, 'role' => 'member', 'joined_at' => now()]]);

        return [$a, $b, $conversation];
    }

    private function member(SchoolClass $class): User
    {
        $user = $this->user('Student');
        Enrollment::factory()->create(['student_profile_id' => StudentProfile::factory()->create(['user_id' => $user->id])->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => 1]);

        return $user;
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
    }
}

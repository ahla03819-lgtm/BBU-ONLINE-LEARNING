<?php

namespace Tests\Feature\Phase5;

use App\Enums\MeetingStatus;
use App\Enums\SchoolClassStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\SchoolClass;
use App\Models\StudentProfile;
use App\Models\TeacherClassAssignment;
use App\Models\TeacherProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MeetingExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_room_handles_initial_connection_failure_without_unsupported_handlers_or_raw_errors(): void
    {
        $component = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));

        $this->assertStringContainsString("onError={() => setConnectionError('Unable to join the meeting. Please try again.')}", $component);
        $this->assertStringContainsString("onDisconnected={() => setConnectionError('The meeting connection was interrupted. Refresh to reconnect.')}", $component);
        $this->assertStringNotContainsString('connected.current) onLeave()', $component);
        $this->assertStringContainsString('useConnectionState', $component);
        $this->assertStringNotContainsString('onReconnecting=', $component);
        $this->assertStringNotContainsString('problem.message', $component);
    }

    public function test_live_room_control_center_keeps_chat_and_reactions_ephemeral_and_room_scoped(): void
    {
        $signals = file_get_contents(resource_path('js/Hooks/Meetings/useMeetingEphemeralSignals.js'));
        $room = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));
        $controls = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingControlCenter.jsx'));

        foreach (['meeting-chat', 'meeting-hand', 'meeting-reaction', 'RoomEvent.DataReceived', 'localParticipant.publishData', 'MAX_MESSAGE_LENGTH = 2000'] as $contract) {
            $this->assertStringContainsString($contract, $signals);
        }
        foreach (['MeetingChatPanel', 'ParticipantsPanel', 'MeetingControlCenter', 'sessionStartedAt'] as $contract) {
            $this->assertStringContainsString($contract, $room);
        }
        $this->assertStringNotContainsString('actual_start_at', $room);
        $this->assertStringContainsString('sharedMeetingElapsedSeconds(meeting, clock, now)', $room);
        $this->assertStringContainsString('if (sharedSeconds === null && sessionStartedAt === null) return null', $room);
        $this->assertStringContainsString('meeting.can_end', $controls);
        $this->assertStringNotContainsString('channels/', $signals);
        $this->assertStringNotContainsString('fetch(', $signals);
    }

    public function test_live_room_keeps_browser_history_navigation_meeting_scoped(): void
    {
        $component = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));

        foreach (['useMeetingNavigationGuard', 'MEETING_HISTORY_GUARD', "window.addEventListener('popstate', restoreMeeting, {capture: true})", 'event.stopImmediatePropagation()', 'window.history.forward()', "window.removeEventListener('popstate', restoreMeeting, {capture: true})", 'overscroll-x-none'] as $contract) {
            $this->assertStringContainsString($contract, $component);
        }
    }

    public function test_room_page_resumes_only_when_existing_server_admission_allows_entry(): void
    {
        $room = file_get_contents(resource_path('js/Pages/Meetings/Room.jsx'));
        $lobby = file_get_contents(resource_path('js/Pages/Meetings/Lobby.jsx'));

        $this->assertStringContainsString('resumeSession', $room);
        $this->assertStringContainsString("if (meeting.status !== 'active' || !meeting.can_join) return;", $lobby);
        $this->assertStringContainsString('if (!meeting.can_bypass_waiting_room && !joinRequest?.can_enter) return;', $lobby);
        $this->assertStringContainsString('resumeAttempted.current = true;', $lobby);
    }

    public function test_media_intent_restores_devices_after_connection_and_never_auto_restarts_screen_capture(): void
    {
        $room = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));
        $controls = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingControlCenter.jsx'));
        $provider = file_get_contents(resource_path('js/Providers/PersistentMeetingProvider.jsx'));

        $this->assertStringContainsString("if (connection !== 'connected' || mediaRestoreStarted.current) return;", $room);
        $this->assertStringContainsString('localParticipant.setCameraEnabled(desiredCamera)', $room);
        $this->assertStringContainsString('localParticipant.setMicrophoneEnabled(desiredMicrophone)', $room);
        $this->assertStringContainsString('Promise.allSettled', $room);
        $this->assertStringContainsString('Resume screen sharing', $controls);
        $this->assertStringContainsString('screenShareIntentChange', $controls);
        $this->assertStringNotContainsString('setScreenShareEnabled(true)', $room.$controls);
        $this->assertStringContainsString('disabled={!available || share.pending}', $controls);
        $this->assertStringContainsString('const stopAll = async () => Promise.allSettled([localParticipant.setCameraEnabled(false), localParticipant.setMicrophoneEnabled(false), localParticipant.setScreenShareEnabled(false)])', $controls);
        $this->assertStringNotContainsString('localParticipant.setScreenShareEnabled(false).catch(() => {})', $controls);
        $this->assertStringContainsString('clearMeetingMediaIntent(current.mediaIntentKey)', $provider);
    }

    public function test_media_restore_is_one_shot_and_permission_failures_do_not_close_the_room(): void
    {
        $room = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));

        $this->assertStringContainsString('if (connection !== \'connected\' || mediaRestoreStarted.current) return;', $room);
        $this->assertStringContainsString('Promise.allSettled', $room);
        $this->assertStringContainsString('setMediaReady(true)', $room);
        $this->assertStringContainsString('could not be restored. Check browser permissions or device availability.', $room);
    }

    public function test_full_and_mini_modes_share_one_room_without_mode_cleanup_stopping_screen_share(): void
    {
        $provider = file_get_contents(resource_path('js/Providers/PersistentMeetingProvider.jsx'));
        $experience = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));
        $controls = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingControlCenter.jsx'));

        $this->assertStringContainsString('{session && <MeetingRoomExperience', $provider);
        $this->assertStringContainsString("if (mode === 'mini') return", $experience);
        $this->assertStringContainsString('<LiveKitRoom token={credentials.token}', $experience);
        $this->assertStringContainsString('useTracks([{source: Track.Source.ScreenShare, withPlaceholder: false}])', $experience);
        $this->assertStringContainsString('onPointerDown={startDrag}', $experience);
        $this->assertStringContainsString('onPointerMove={moveDrag}', $experience);
        $this->assertStringContainsString("target?.closest('button, a, input, select, textarea, [role=\"button\"], [data-no-drag]')", $experience);
        $this->assertStringContainsString('new ResizeObserver(clampToViewport)', $experience);
        $this->assertStringContainsString('max-h-[calc(100dvh-2rem)]', $experience);
        $this->assertStringContainsString('localParticipant.setScreenShareEnabled(false)', $controls);
        $this->assertStringNotContainsString('localParticipant.setScreenShareEnabled(false).catch(() => {})', $controls);
    }

    public function test_full_and_mini_leave_explicitly_disconnect_and_drag_header_does_not_cover_leave(): void
    {
        $experience = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));
        $controls = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingControlCenter.jsx'));

        $this->assertStringContainsString('await stopAll();', $controls);
        $this->assertStringContainsString('await onLeave(() => room.disconnect());', $controls);
        $this->assertStringContainsString('await Promise.allSettled([localParticipant.setCameraEnabled(false), localParticipant.setMicrophoneEnabled(false), localParticipant.setScreenShareEnabled(false)]);', $experience);
        $this->assertStringContainsString('await onLeave(() => room.disconnect());', $experience);
        $headerEnd = strpos($experience, '</header>', strpos($experience, 'aria-label="Move mini meeting window"'));
        $miniLeave = strpos($experience, 'onClick={leave}', $headerEnd);
        $this->assertNotFalse($headerEnd);
        $this->assertNotFalse($miniLeave);
        $this->assertGreaterThan($headerEnd, $miniLeave);
    }

    public function test_explicit_leave_awaits_application_cleanup_and_inertia_replaces_the_full_room_page(): void
    {
        $provider = file_get_contents(resource_path('js/Providers/PersistentMeetingProvider.jsx'));
        $lobby = file_get_contents(resource_path('js/Pages/Meetings/Lobby.jsx'));

        $this->assertStringContainsString('await current.onLeave?.();', $provider);
        $this->assertStringContainsString('await disconnectRoom?.();', $provider);
        $this->assertStringContainsString('router.visit(current.lobbyUrl, {replace: true});', $provider);
        $this->assertStringContainsString('clearMeetingMediaIntent(current.mediaIntentKey)', $provider);
        $this->assertStringContainsString('await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`', $lobby);
        $this->assertStringContainsString('if (!meeting.can_bypass_waiting_room && joinRequest?.status === \'admitted\')', $lobby);
    }

    public function test_persistent_session_enters_the_room_through_inertia_without_replacing_the_lobby_history_entry(): void
    {
        $provider = file_get_contents(resource_path('js/Providers/PersistentMeetingProvider.jsx'));
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
        $notifications = file_get_contents(resource_path('js/Hooks/useNotificationRealtime.js'));

        $this->assertStringContainsString('const roomUrlPath = meetingPath(nextSession.roomUrl);', $provider);
        $this->assertStringContainsString('if (window.location.pathname !== roomUrlPath) router.visit(nextSession.roomUrl);', $provider);
        $this->assertStringNotContainsString("window.history.replaceState(window.history.state, '', nextSession.roomUrl)", $provider);
        $this->assertStringContainsString('pauseBackgroundRefresh', $layout);
        $this->assertStringContainsString('pauseBackgroundRefreshRef.current', $notifications);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_current_student_can_open_scheduled_lobby_without_join_authorization_or_technical_props(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->create(['school_class_id' => $class->id]);

        $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Meetings/Lobby')->where('meeting.status', 'scheduled')->where('meeting.title', $meeting->title)->where('meeting.can_join', false)->where('meeting.session_started_at', null)
            ->missing('meeting.livekit_room_name')->missing('meeting.livekit_identity')->missing('meeting.token'));
        $this->assertDatabaseCount('meeting_participants', 0);
    }

    public function test_active_current_student_and_teacher_can_open_room_but_historical_and_unrelated_users_are_denied(): void
    {
        $class = $this->activeClass();
        $startedAt = now()->subMinutes(4)->startOfSecond();
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id, 'session_started_at' => $startedAt]);
        foreach ([$this->student($class), $this->teacher($class)] as $current) {
            $this->actingAs($current)->get(route('meetings.room', [$class, $meeting]))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Meetings/Room')->where('meeting.can_join', true)->where('meeting.session_started_at', $startedAt->toIso8601String()));
        }
        foreach ([$this->student($class, false), $this->teacher($class, false), $this->roleUser('Student')] as $denied) {
            $this->actingAs($denied)->get(route('meetings.lobby', [$class, $meeting]))->assertForbidden();
        }
    }

    public function test_cross_class_nesting_is_not_found_for_lobby_and_room(): void
    {
        $class = $this->activeClass();
        $other = SchoolClass::factory()->create(['academic_year_id' => $class->academic_year_id, 'status' => SchoolClassStatus::Active]);
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        $this->actingAs($student)->get(route('meetings.lobby', [$other, $meeting]))->assertNotFound();
        $this->actingAs($student)->get(route('meetings.room', [$other, $meeting]))->assertNotFound();
    }

    public function test_removed_participant_is_denied_both_pages(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        $meeting = Meeting::factory()->active()->create(['school_class_id' => $class->id]);
        MeetingParticipant::factory()->create(['meeting_id' => $meeting->id, 'user_id' => $student->id, 'removed_at' => now()]);
        $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertForbidden();
        $this->actingAs($student)->get(route('meetings.room', [$class, $meeting]))->assertForbidden();
    }

    public function test_ended_and_cancelled_lobbies_are_read_only_and_rooms_are_denied(): void
    {
        $class = $this->activeClass();
        $student = $this->student($class);
        foreach ([MeetingStatus::Ended, MeetingStatus::Cancelled] as $status) {
            $meeting = Meeting::factory()->create(['school_class_id' => $class->id, 'status' => $status]);
            $this->actingAs($student)->get(route('meetings.lobby', [$class, $meeting]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('meeting.can_join', false));
            $this->actingAs($student)->get(route('meetings.room', [$class, $meeting]))->assertForbidden();
        }
    }

    private function activeClass(): SchoolClass
    {
        return SchoolClass::factory()->create(['academic_year_id' => AcademicYear::factory()->active(), 'status' => SchoolClassStatus::Active]);
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function student(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Student');
        $profile = StudentProfile::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->create(['student_profile_id' => $profile->id, 'academic_year_id' => $class->academic_year_id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ended_on' => $current ? null : now()]);

        return $user;
    }

    private function teacher(SchoolClass $class, bool $current = true): User
    {
        $user = $this->roleUser('Teacher');
        $profile = TeacherProfile::factory()->create(['user_id' => $user->id]);
        TeacherClassAssignment::factory()->create(['teacher_profile_id' => $profile->id, 'school_class_id' => $class->id, 'current_slot' => $current ? 1 : null, 'ends_on' => $current ? null : now()]);

        return $user;
    }
}

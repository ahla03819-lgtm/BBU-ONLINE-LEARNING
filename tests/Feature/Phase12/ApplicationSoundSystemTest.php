<?php

namespace Tests\Feature\Phase12;

use Tests\TestCase;

class ApplicationSoundSystemTest extends TestCase
{
    public function test_client_sound_system_is_local_persisted_safe_and_deduplicated(): void
    {
        $sounds = file_get_contents(resource_path('js/Sound/AppSounds.jsx'));

        foreach ([
            "const preferenceKey = 'bbu.app-sounds.enabled'",
            'window.localStorage.getItem(preferenceKey)',
            'window.localStorage.setItem(preferenceKey',
            'const setSoundEnabled',
            'setEnabled(next)',
            'AudioContext || window.webkitAudioContext',
            'audio.resume().then',
            'recentEvents = new Map()',
            'shouldPlay',
            'notification:',
            "'waiting-room-request':",
            'admitted:',
            "'participant-joined':",
            "'participant-left':",
            "'meeting-ended':",
            'reconnect:',
            'return false;',
            '!readPreference()',
            '.catch(() => {})',
        ] as $contract) {
            $this->assertStringContainsString($contract, $sounds);
        }

        $this->assertStringNotContainsString('http://', $sounds);
        $this->assertStringNotContainsString('https://', $sounds);
    }

    public function test_notifications_waiting_room_and_livekit_use_the_shared_sound_contract(): void
    {
        $notificationHook = file_get_contents(resource_path('js/Hooks/useNotificationRealtime.js'));
        $messagesHook = file_get_contents(resource_path('js/Hooks/useChannelMessages.js'));
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
        $app = file_get_contents(resource_path('js/app.jsx'));
        $lobby = file_get_contents(resource_path('js/Pages/Meetings/Lobby.jsx'));
        $room = file_get_contents(resource_path('js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx'));
        $account = file_get_contents(resource_path('js/Pages/MyAccount/Show.jsx'));

        foreach (['onNewNotification', 'onNewNotificationRef.current?.(event.notification)', "sounds.play('notification'", 'AppSoundsProvider'] as $contract) {
            $this->assertStringContainsString($contract, $notificationHook.$layout.$app);
        }
        foreach (['onMessageReceived', 'heardMessages', 'String(event.message?.sender?.id) !== String(user.id)'] as $contract) {
            $this->assertStringContainsString($contract, $messagesHook);
        }
        foreach (["sounds.play('waiting-room-request'", "sounds.play('admitted'", 'knownPendingRequests', 'window.clearInterval(interval)'] as $contract) {
            $this->assertStringContainsString($contract, $lobby);
        }
        foreach (['MeetingRoomSounds', "sounds.play('participant-joined'", "sounds.play('participant-left'", "sounds.play('reconnect'", "sounds.play('meeting-ended'", 'knownParticipants'] as $contract) {
            $this->assertStringContainsString($contract, $room);
        }
        foreach (['Application sounds', 'sounds.setEnabled(!sounds.enabled)', 'role="switch"'] as $contract) {
            $this->assertStringContainsString($contract, $account);
        }
    }
}

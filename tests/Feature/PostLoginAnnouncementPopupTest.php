<?php

namespace Tests\Feature;

use Tests\TestCase;

class PostLoginAnnouncementPopupTest extends TestCase
{
    public function test_announcement_popup_is_an_authenticated_shell_component_with_per_login_session_state_and_auto_dismissal(): void
    {
        $popup = file_get_contents(resource_path('js/Components/Announcements/AnnouncementPopup.jsx'));
        $layout = file_get_contents(resource_path('js/Layouts/AuthenticatedLayout.jsx'));
        $login = file_get_contents(resource_path('js/Pages/Auth/Login.jsx'));

        foreach ([
            'bbu.announcement.${announcementVersion}.shown.${userId}',
            'window.sessionStorage.getItem(key)',
            'window.sessionStorage.setItem(key, \'true\')',
            'window.sessionStorage.removeItem(storageKey(userId))',
            'const DISPLAY_DURATION = 6000',
            'const ENTER_DURATION = 350',
            'const EXIT_DURATION = 350',
            'window.clearTimeout(enterTimer)',
            "setPhase('exiting')",
            'role="dialog"',
            'aria-modal="true"',
            'object-contain',
            'bbu-announcement-2026.png',
        ] as $contract) {
            $this->assertStringContainsString($contract, $popup);
        }

        foreach (['AnnouncementPopup userId={auth.user.id}', 'clearAnnouncementSession(auth.user.id)', "router.on('before'", "String(visit.url).endsWith('/logout')"] as $contract) {
            $this->assertStringContainsString($contract, $layout);
        }
        $this->assertStringNotContainsString('AnnouncementPopup', $login);
        $this->assertFileExists(resource_path('js/assets/bbu-announcement-2026.png'));
    }
}

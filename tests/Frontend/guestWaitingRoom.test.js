import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {guestAccessTerminalStatuses, guestTerminalStatuses, startGuestStatusPolling} from '../../resources/js/Pages/GuestMeetings/guestWaitingRoomPolling.js';

const root = resolve(process.cwd(), 'resources/js');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

test('guest join and waiting pages are dedicated pages without the authenticated application shell', () => {
    const join = read('Pages/GuestMeetings/Join.jsx');
    const waiting = read('Pages/GuestMeetings/WaitingRoom.jsx');

    assert.match(join, /Request to join|guestMeeting\.join\.request/);
    assert.match(waiting, /guestMeeting\.waiting\.admittedNotice/);
    assert.doesNotMatch(join, /AuthenticatedLayout|AppSidebar|Dashboard/);
    assert.doesNotMatch(waiting, /AuthenticatedLayout|AppSidebar|LiveKit/);
});

test('guest polling stops for terminal outcomes and bounds repeated failures', async () => {
    assert.equal(guestTerminalStatuses.has('admitted'), true);
    assert.equal(guestTerminalStatuses.has('rejected'), true);
    let calls = 0;
    const stop = startGuestStatusPolling({
        intervalMs: 1,
        fetchStatus: async () => { calls += 1; return {status: 'admitted'}; },
        onStatus: () => {},
        onUnavailable: () => assert.fail('terminal success must not be unavailable'),
    });
    await new Promise((resolve) => setTimeout(resolve, 10));
    stop();
    assert.equal(calls, 1);

    let failures = 0;
    const stopFailing = startGuestStatusPolling({
        intervalMs: 1,
        maxFailures: 2,
        fetchStatus: async () => { throw new Error('offline'); },
        onStatus: () => {},
        onUnavailable: () => { failures += 1; },
    });
    await new Promise((resolve) => setTimeout(resolve, 15));
    stopFailing();
    assert.equal(failures, 1);
});

test('host guest request list keeps guests separate from authenticated waiting requests', () => {
    const lobby = read('Pages/Meetings/Lobby.jsx');

    assert.match(lobby, /guest-requests/);
    assert.match(lobby, /GuestRequests/);
    assert.match(lobby, /'rejected'/);
    assert.match(lobby, /GuestInvitation/);
    assert.match(lobby, /guest-invitations/);
    assert.match(lobby, /invite_url/);
});

test('guest request polling does not move fullscreen before the authenticated token flow', () => {
    const lobby = read('Pages/Meetings/Lobby.jsx');
    const start = lobby.indexOf('const issueToken = async');
    const end = lobby.indexOf('const join = async', start);
    const issueToken = lobby.slice(start, end);

    assert(issueToken.indexOf('const fullscreenResult = await enterFullscreen()') < issueToken.indexOf('/token'));
});

test('guest terminal redirects clear guest flow without subscribing to authenticated channels', () => {
    const waiting = read('Pages/GuestMeetings/WaitingRoom.jsx');
    const exit = read('Pages/GuestMeetings/Exit.jsx');

    assert.equal(guestAccessTerminalStatuses.has('admitted'), false);
    assert.equal(guestAccessTerminalStatuses.has('removed'), true);
    assert.match(waiting, /window\.location\.replace\(session\.terminal_url\)/);
    assert.match(waiting, /session\.status !== 'rejected'/);
    assert.match(waiting, /guestMeeting\.waiting\.exit/);
    assert.match(waiting, /fetch\(leaveUrl, \{method: 'POST'/);
    assert.match(exit, /authenticated/);
    assert.doesNotMatch(waiting, /AuthenticatedLayout|AppSidebar|Echo\.|private\(/);
});

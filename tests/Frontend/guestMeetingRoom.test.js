import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const root = resolve(process.cwd(), 'resources/js');
const read = (path) => readFileSync(resolve(root, path), 'utf8');

test('guest room uses a dedicated least-privilege LiveKit surface', () => {
    const source = read('Pages/GuestMeetings/Room.jsx');

    assert.match(source, /LiveKitRoom/);
    assert.match(source, /RoomAudioRenderer/);
    assert.match(source, /useLocalParticipant/);
    assert.match(source, /screen=\{false\}/);
    assert.doesNotMatch(source, /AuthenticatedLayout|AppSidebar|MeetingControlCenter|MeetingRecording|screen-share-requests/);
});

test('guest room requests a protected token only after the guest room transition', () => {
    const source = read('Pages/GuestMeetings/Room.jsx');

    assert.match(source, /fetch\(tokenUrl, \{method: 'POST'/);
    assert.match(source, /: <LiveKitRoom serverUrl=\{credentials\.serverUrl\}/);
    assert.doesNotMatch(source, /token=.*url/);
    assert.match(source, /router\.visit\(waitingUrl\)/);
});

test('guest waiting room exposes a server-provided transition only after admission', () => {
    const source = read('Pages/GuestMeetings/WaitingRoom.jsx');

    assert.match(source, /session\.can_join_room && session\.room_url/);
    assert.match(source, /router\.visit\(session\.room_url\)/);
    assert.doesNotMatch(source, /LiveKitRoom/);
});

test('guest room retains English and Khmer localization hooks and accessible media controls', () => {
    const room = read('Pages/GuestMeetings/Room.jsx');
    const english = read('i18n/en.js');
    const khmer = read('i18n/km.js');

    assert.match(room, /aria-pressed=\{isCameraEnabled\}/);
    assert.match(room, /aria-pressed=\{isMicrophoneEnabled\}/);
    assert.match(room, /guestMeeting\.room\./);
    assert.match(english, /guestMeeting:\s*\{/);
    assert.match(english, /room:\s*\{/);
    assert.match(khmer, /guestMeeting:\s*\{/);
    assert.match(khmer, /room:\s*\{/);
});

test('guest room polls only guest state and validates disconnects before terminal redirect', () => {
    const room = read('Pages/GuestMeetings/Room.jsx');

    assert.match(room, /startGuestStatusPolling/);
    assert.match(room, /guestAccessTerminalStatuses/);
    assert.match(room, /onDisconnected=\{disconnected\}/);
    assert.match(room, /await status\(\)/);
    assert.match(room, /window\.location\.replace\(nextSession\.terminal_url\)/);
    assert.doesNotMatch(room, /Echo\.|private\(/);
});

test('guest room leave uses the guest-scoped endpoint and replaces browser history', () => {
    const room = read('Pages/GuestMeetings/Room.jsx');

    assert.match(room, /fetch\(leaveUrl, \{method: 'POST'/);
    assert.match(room, /window\.location\.replace\(data\.redirect_url\)/);
    assert.doesNotMatch(room, /logout/);
});

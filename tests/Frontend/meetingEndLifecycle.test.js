import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {meetingExperienceUrls} from '../../resources/js/Pages/Meetings/meetingExperienceUrls.js';
import {completeConfirmedMeetingEnd} from '../../resources/js/Components/Meetings/LiveKit/meetingEndTeardown.js';

const root = resolve(process.cwd(), 'resources/js');
const read = (path) => readFileSync(resolve(root, path), 'utf8');
const between = (source, start, end) => source.slice(source.indexOf(start), source.indexOf(end, source.indexOf(start)));

test('host End uses the lifecycle endpoint and does not call participant Leave', () => {
    const moderation = read('Hooks/Meetings/useMeetingModeration.js');
    const end = between(moderation, 'const end = async', 'const decideScreenShare');

    assert.match(end, /fetch\(`\/school-classes\/\$\{schoolClass\.id\}\/meetings\/\$\{meeting\.uuid\}\/end`, \{method: 'POST'/);
    assert.match(end, /if \(!response\.ok\) throw new Error\(\);/);
    assert.match(end, /await onMeetingEnded\?\.\(\);/);
    assert.doesNotMatch(end, /onLeave\(|leaveMeeting|waiting-room/);
    assert(end.indexOf('if (!response.ok)') < end.indexOf('await onMeetingEnded?.()'), 'cleanup only follows a confirmed lifecycle response');
});

test('a confirmed End explicitly stops local media, exits fullscreen, disconnects, and clears the persistent session', () => {
    const room = read('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    const end = between(room, 'const endMeeting = useCallback', 'const moderation = useMeetingModeration');
    const teardown = read('Components/Meetings/LiveKit/meetingEndTeardown.js');
    const provider = read('Providers/PersistentMeetingProvider.jsx');

    for (const operation of ['setCameraEnabled(false)', 'setMicrophoneEnabled(false)', 'setScreenShareEnabled(false)']) {
        assert.match(end, new RegExp(operation.replace(/[()]/g, '\\$&')));
    }
    assert.match(end, /Promise\.allSettled/);
    assert.match(end, /disconnectRoom: \(\) => room\.disconnect\(\)/);
    assert.match(end, /navigate: onEnd/);
    assert.match(teardown, /await disconnectRoom\(\);/);
    assert.match(teardown, /await navigate\(\);/);
    assert.match(room, /onMeetingEnded: endMeeting/);
    assert.match(room, /const exitMeetingFullscreen = useCallback/);
    assert.match(room, /await exitFullscreen\(\);/);
    assert.match(room, /setIsFullscreen\(false\);/);
    assert.match(room, /const handleEnd = useCallback/);
    assert.match(provider, /clearMeetingMediaIntent\(current\.mediaIntentKey\)/);
    assert.match(provider, /await disconnectRoom\?\.\(\);/);
    assert.match(provider, /const destination = current\.lobbyUrl;/);
    assert.match(provider, /router\.visit\(lobbyUrl, \{/);
    assert.match(provider, /fallbackNavigation: true/);
    assert.match(provider, /window\.location\.assign\(lobbyUrl\);/);
});

test('ordinary Leave retains its cancellation-safe navigation semantics', () => {
    const provider = read('Providers/PersistentMeetingProvider.jsx');
    const leave = between(provider, 'const leaveMeeting = useCallback', 'const endMeeting = useCallback');
    const end = between(provider, 'const endMeeting = useCallback', "useEffect(() => router.on");

    assert.match(leave, /clearSession\(\{[\s\S]*disconnectRoom,/);
    assert.doesNotMatch(leave, /fallbackNavigation/);
    assert.match(end, /destination,/);
    assert.match(end, /fallbackNavigation: true/);
});

test('the End destination uses the production class and meeting lobby URL', () => {
    const urls = meetingExperienceUrls(42, 'a1b2c3d4-e5f6-7890-abcd-ef1234567890');

    assert.equal(urls.lobbyUrl, '/collaboration/classes/42/meetings/a1b2c3d4-e5f6-7890-abcd-ef1234567890/lobby');
    assert.equal(urls.roomUrl, '/collaboration/classes/42/meetings/a1b2c3d4-e5f6-7890-abcd-ef1234567890/room');
    assert.notEqual(urls.lobbyUrl, urls.roomUrl);
    assert.match(read('Pages/Meetings/Lobby.jsx'), /meetingExperienceUrls\(schoolClass\.id, meeting\.uuid\)/);
});

test('the initiating host disconnects before a competing terminal navigation can settle', async () => {
    const calls = [];
    let finishNavigation;
    const end = completeConfirmedMeetingEnd({
        stopMedia: async () => { calls.push('media'); },
        disconnectRoom: async () => { calls.push('disconnect'); },
        navigate: () => {
            calls.push('navigate');
            return new Promise((resolve) => { finishNavigation = resolve; });
        },
    });

    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(calls, ['media', 'disconnect', 'navigate']);
    finishNavigation();
    await end;
});

test('remote ending events still close full and mini sessions through the same persistent provider path', () => {
    const provider = read('Providers/PersistentMeetingProvider.jsx');
    const eventLoop = between(provider, "const events = ['scheduled'", 'const participantRemoved');
    const terminalEffect = between(provider, 'if (session && [\'ending\'', 'const mode = session');

    assert.match(eventLoop, /'ending', 'ended', 'cancelled'/);
    assert.match(terminalEffect, /endMeeting\(\);/);
    assert.match(provider, /const mode = session && pathname === meetingPath\(session\.roomUrl\) \? 'full' : 'mini';/);
});

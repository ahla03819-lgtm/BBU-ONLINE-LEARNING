import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const root = resolve(process.cwd(), 'resources/js');

function readSource(relativePath) {
    return readFileSync(resolve(root, relativePath), 'utf8');
}

test('useMeetingFullscreen hook exists and exports expected functions', async () => {
    const source = readSource('Hooks/Meetings/useMeetingFullscreen.js');
    assert.match(source, /export default function useMeetingFullscreen/);
    assert.match(source, /enterFullscreen/);
    assert.match(source, /exitFullscreen/);
    assert.match(source, /toggleFullscreen/);
    assert.match(source, /fullscreenSupported/);
    assert.match(source, /isFullscreen/);
});

test('Lobby Join click requests fullscreen BEFORE asynchronous join work', () => {
    const source = readSource('Pages/Meetings/Lobby.jsx');
    assert.match(source, /enterFullscreen\(\)/);
    assert.match(source, /setJoining\(true\);\s*setError\(null\);/);
    const fullscreenIdx = source.indexOf('const fullscreenResult = await enterFullscreen()');
    const fetchIdx = source.indexOf('const response = await fetch');
    assert(fullscreenIdx < fetchIdx, 'fullscreen requested before token fetch (async join work)');
});

test('Meeting room and authenticated layout are inside the fullscreen provider', () => {
    const appSource = readSource('app.jsx');
    const fullscreenStart = appSource.indexOf('<FullscreenProvider>');
    const meetingStart = appSource.indexOf('<PersistentMeetingProvider>');
    const meetingEnd = appSource.indexOf('</PersistentMeetingProvider>');
    const fullscreenEnd = appSource.indexOf('</FullscreenProvider>');

    assert(fullscreenStart >= 0 && fullscreenStart < meetingStart, 'fullscreen provider opens before persistent meeting provider');
    assert(meetingEnd >= 0 && meetingEnd < fullscreenEnd, 'persistent meeting provider closes inside fullscreen provider');
    assert.equal(appSource.match(/<FullscreenProvider>/g)?.length, 1, 'there is exactly one fullscreen provider');
});

test('Failed joins unwind successfully-entered fullscreen without masking the join error', async () => {
    const {exitFullscreenAfterJoinFailure} = await import('../../resources/js/Hooks/Meetings/useMeetingFullscreen.js');
    let exitCalls = 0;

    await exitFullscreenAfterJoinFailure({ok: true}, async () => { exitCalls += 1; });
    assert.equal(exitCalls, 1, 'successful fullscreen entry is unwound');

    await assert.doesNotReject(() => exitFullscreenAfterJoinFailure({ok: true}, async () => {
        exitCalls += 1;
        throw new Error('exit failed');
    }));
    assert.equal(exitCalls, 2, 'fullscreen exit is attempted and its failure is swallowed');

    await exitFullscreenAfterJoinFailure({ok: false}, async () => { exitCalls += 1; });
    assert.equal(exitCalls, 2, 'failed fullscreen entry does not request an exit');

    const lobbySource = readSource('Pages/Meetings/Lobby.jsx');
    const catchStart = lobbySource.indexOf('} catch (problem) {', lobbySource.indexOf('const issueToken'));
    const finallyStart = lobbySource.indexOf('finally', catchStart);
    const failurePath = lobbySource.slice(catchStart, finallyStart);
    assert.match(failurePath, /await exitFullscreenAfterJoinFailure\(fullscreenResult, exitFullscreen\)/);
    assert.match(failurePath, /setError\(/, 'existing failure notice remains on the join failure path');
});

test('Full-room connection errors use the translated notice prop', () => {
    const source = readSource('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    assert.match(source, /<ConnectionStatus notice=\{connectionError\}\/>/);
    assert.doesNotMatch(source, /<ConnectionStatus error=\{connectionError\}\/>/);
});

test('Room positioning distinguishes browser fullscreen from normal app chrome', () => {
    const source = readSource('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    // Fullscreen is a self-contained viewport-height column; the app-chrome
    // variant keeps the topbar/sidebar offsets and scrolls.
    assert.match(source, /isFullscreen \? 'inset-0 h-\[100dvh\] overflow-hidden p-0' : 'inset-x-0 bottom-0 top-14 overflow-y-auto p-3 sm:p-4 lg:left-56 lg:p-6'/);
    assert.doesNotMatch(source, /className=\{`fixed inset-0/);
    assert.doesNotMatch(source, /xl:grid-cols-\[minmax\(0,1fr\)_22rem\]/);
});

test('Fullscreen request rejection does not stop the meeting join flow', () => {
    const source = readSource('Pages/Meetings/Lobby.jsx');
    assert.match(source, /if \(!fullscreenResult\.ok && fullscreenResult\.reason !== 'unsupported'\)/);
    assert.match(source, /Fullscreen was denied but we continue joining anyway/);
    const fetchIdx = source.indexOf('const response = await fetch');
    const fullscreenIdx = source.indexOf('const fullscreenResult = await enterFullscreen()');
    assert(fullscreenIdx < fetchIdx, 'fullscreen requested before token fetch');
    assert.match(source, /} catch \(problem\)/, 'join continues even if fullscreen fails');
});

test('Explicit Leave exits fullscreen', () => {
    const source = readSource('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    assert.match(source, /const handleLeave = useCallback\(async \(cleanup\) =>/);
    assert.match(source, /if \(isFullscreen\) \{/);
    assert.match(source, /await exitFullscreen\(\)/);
    assert.match(source, /await onLeave\(cleanup\)/);
});

test('MiniMeetingWindow leave exits fullscreen', () => {
    const source = readSource('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    const miniStart = source.indexOf('function MiniMeetingWindow');
    const leaveIdx = source.indexOf('const leave = async', miniStart);
    assert(leaveIdx > 0, 'MiniMeetingWindow has leave function');
    assert.match(source.slice(miniStart), /if \(isFullscreen\) \{/);
    assert.match(source.slice(miniStart), /await exitFullscreen\(\)/);
    assert.match(source.slice(miniStart), /await onLeave\(\(\) => room\.disconnect\(\)\)/);
});

test('Meeting ended/cancelled exits fullscreen', () => {
    const source = readSource('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    assert.match(source, /\['ending', 'ended', 'cancelled'\]\.includes\(meeting\.status\)/);
    assert.match(source, /useEffect\(\(\) => \{/);
    assert.match(source, /if \(isFullscreen\) \{/);
    assert.match(source, /exitFullscreen\(\)/);
});

test('Browser ESC/fullscreenchange sets isFullscreen false without disconnecting', () => {
    const source = readSource('Hooks/Meetings/useMeetingFullscreen.js');
    assert.match(source, /document\.addEventListener\('fullscreenchange'/);
    assert.match(source, /setIsFullscreen\(document\.fullscreenElement !== null\)/);
    assert.doesNotMatch(source, /room\.disconnect|onLeave|stopAll/);
});

test('Manual Full screen button enters fullscreen', () => {
    const source = readSource('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    assert.match(source, /enterFullscreen/);
    assert.match(source, /isFullscreen \? exitFullscreen\(\) : enterFullscreen\(\)/);
    assert.match(source, /meetingRoom\.stage\.fullscreen\.enter/);
    assert.match(source, /aria-pressed=\{isFullscreen\}/);
    assert.match(source, /fullscreenSupported/);
});

test('Manual Exit full screen button exits fullscreen', () => {
    const source = readSource('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    assert.match(source, /isFullscreen \? exitFullscreen\(\) : enterFullscreen\(\)/);
    assert.match(source, /meetingRoom\.stage\.fullscreen\.exit/);
});

test('Unsupported fullscreen is handled safely', () => {
    const hookSource = readSource('Hooks/Meetings/useMeetingFullscreen.js');
    assert.match(hookSource, /fullscreenSupported/);
    assert.match(hookSource, /reason: 'unsupported'/);
    const ccSource = readSource('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    // When fullscreen is unsupported, the fullscreen button is conditionally hidden
    assert.match(ccSource, /\{fullscreenSupported &&/);
    assert.match(ccSource, /meetingRoom\.stage\.fullscreen\.(enter|exit)/);
});

test('Refresh/media persistence contracts remain unchanged', () => {
    const lobbySource = readSource('Pages/Meetings/Lobby.jsx');
    assert.match(lobbySource, /mediaIntentKey/);
    assert.match(lobbySource, /readMeetingMediaIntent/);
    assert.match(lobbySource, /writeMeetingMediaIntent/);
    assert.match(lobbySource, /resumeSession/);
    assert.match(lobbySource, /mediaIntent/);
    const meetingRoomSource = readSource('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    assert.match(meetingRoomSource, /mediaRestoreStarted/);
    assert.match(meetingRoomSource, /restoreMedia/);
});

test('Existing meeting control and moderation behavior remains intact', () => {
    const ccSource = readSource('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    // signals is passed as prop and used for reactions, hand raising
    assert.match(ccSource, /signals\.toggleHand/);
    assert.match(ccSource, /signals\.sendReaction/);
    assert.match(ccSource, /meeting\.can_screen_share/);
    assert.match(ccSource, /screenShareChanged/);
    assert.match(ccSource, /screenShareIntentChange/);
    assert.match(ccSource, /stopAll/);
    // Moderation is in MeetingRoomExperience
    const mrSource = readSource('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    assert.match(mrSource, /useMeetingModeration/);
});

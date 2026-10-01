import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {resolveKey} from '../../resources/js/i18n/translate.js';
import {
    isActiveScreenShareRequest,
    screenShareButtonState,
    screenShareLabelKey,
    shouldStartScreenCapture,
} from '../../resources/js/Components/Meetings/LiveKit/screenShareApproval.js';

const root = resolve(process.cwd(), 'resources/js');
const source = (path) => readFileSync(resolve(root, path), 'utf8');
const repoFile = (path) => readFileSync(resolve(process.cwd(), path), 'utf8');

test('student button progresses through request, pending, approved, sharing, then requires a new request', () => {
    const student = {canShareDirectly: false, requiresApproval: true, isSharing: false, requesting: false};
    assert.equal(screenShareButtonState({...student, requestStatus: null}), 'request');
    assert.equal(screenShareButtonState({...student, requesting: true, requestStatus: null}), 'requesting');
    assert.equal(screenShareButtonState({...student, requestStatus: 'pending'}), 'pending');
    assert.equal(screenShareButtonState({...student, requestStatus: 'approved'}), 'approved');
    assert.equal(screenShareButtonState({...student, isSharing: true, requestStatus: 'approved'}), 'sharing');
    assert.equal(screenShareButtonState({...student, requestStatus: 'consumed'}), 'request');
    assert.equal(screenShareLabelKey('approved'), 'meetingRoom.screenShare.start');
});

test('pending/requesting approval never permits capture while approved requires a user click', () => {
    assert.equal(shouldStartScreenCapture('request'), false);
    assert.equal(shouldStartScreenCapture('requesting'), false);
    assert.equal(shouldStartScreenCapture('pending'), false);
    assert.equal(shouldStartScreenCapture('approved'), true);

    const controls = source('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    assert.match(controls, /if \(shareState === 'request'\) return screenShareApproval\?\.request\(\);/);
    assert.match(controls, /if \(!shouldStartScreenCapture\(shareState\)\) return;/);
    assert.match(controls, /share\.buttonProps\.onClick\(event\)/);
    assert.match(controls, /\['requesting', 'pending'\]\.includes\(shareState\)/);
});

test('approval never automatically starts browser capture', () => {
    const hook = source('Hooks/Meetings/useMeetingScreenShareApproval.js');
    const room = source('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    assert.doesNotMatch(hook, /getDisplayMedia|setScreenShareEnabled|buttonProps\.onClick/);
    assert.doesNotMatch(room, /getDisplayMedia/);
});

test('a student who requires approval gets a wired Share control that calls the request API', () => {
    const controls = source('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    const room = source('Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    // The hook is mounted in the room and handed down to the control bar.
    assert.match(room, /useMeetingScreenShareApproval\(\{meeting, schoolClass, connected: connection === 'connected'/);
    assert.match(room, /<MeetingControlCenter[^>]*screenShareApproval=\{screenShareApproval\}/);
    // A student who cannot share directly still gets the control.
    assert.match(controls, /meeting\.can_screen_share \|\| meeting\.requires_screen_share_approval/);
    assert.match(controls, /canShareDirectly: meeting\.can_screen_share, requiresApproval: meeting\.requires_screen_share_approval/);
    // Clicking it in the request state calls the request API, not LiveKit.
    assert.match(controls, /if \(shareState === 'request'\) return screenShareApproval\?\.request\(\);/);
    // The control is not disabled while an approval-gated request is idle.
    assert.match(controls, /const screenShareDisabled = !available \|\| share\.pending \|\| \['requesting', 'pending'\]\.includes\(shareState\)/);
});

test('the request POST targets the same class and meeting scoped route Laravel defines', () => {
    const hook = source('Hooks/Meetings/useMeetingScreenShareApproval.js');
    const routes = repoFile('routes/web.php');
    assert.match(hook, /const base = `\/collaboration\/classes\/\$\{schoolClass\.id\}\/meetings\/\$\{meeting\.uuid\}\/screen-share-requests`/);
    assert.match(hook, /fetch\(base, \{method: 'POST'/);
    assert.match(routes, /Route::post\('\/collaboration\/classes\/\{schoolClass\}\/meetings\/\{meeting:uuid\}\/screen-share-requests'.*->name\('meetings\.screen-share-requests\.store'\)/);
});

test('capture stays reachable only from the guarded click handler', () => {
    const controls = source('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    assert.match(controls, /if \(!shouldStartScreenCapture\(shareState\)\) return;/);
    assert.match(controls, /share\.buttonProps\.onClick\(event\)/);
    assert.doesNotMatch(controls, /getDisplayMedia/);
});

test('rejection uses a locale-reactive notice descriptor', () => {
    const hook = source('Hooks/Meetings/useMeetingScreenShareApproval.js');
    assert.match(hook, /current\.status === 'rejected'\) onNotice\(noticeKey\('meetingRoom\.screenShare\.declined'\)\)/);
    assert.doesNotMatch(hook, /set[A-Za-z]+\(['"]Your screen sharing request was declined/);
});

test('host controls render pending requests and scoped approve/reject actions', () => {
    const panel = source('Components/Meetings/LiveKit/MeetingSidePanel.jsx');
    const moderation = source('Hooks/Meetings/useMeetingModeration.js');
    assert.match(panel, /ScreenShareRequestsSection/);
    assert.match(panel, /screenShareRequests\.map/);
    assert.match(panel, /decideScreenShare\(request, 'approved'\)/);
    assert.match(panel, /decideScreenShare\(request, 'rejected'\)/);
    assert.match(moderation, /screen-share-requests\/\$\{encodeURIComponent\(request\.reference\)\}/);
    assert.match(moderation, /meeting\.screen-share-request-changed/);
});

// A Host moderating a room watches the People panel, which is also where waiting
// room requests appear. Asserting that the section name merely appears somewhere
// in the file passed while the People panel still showed nothing, so each panel
// body is sliced on its own boundaries and inspected separately.
test('every moderator panel a Host opens renders the pending screen share requests', () => {
    const panel = source('Components/Meetings/LiveKit/MeetingSidePanel.jsx');
    const slice = (startMarker, endMarker) => {
        const start = panel.indexOf(startMarker);
        assert.notEqual(start, -1, `${startMarker} must exist in MeetingSidePanel.jsx`);
        const end = panel.indexOf(endMarker, start + startMarker.length);
        assert.notEqual(end, -1, `${endMarker} must follow ${startMarker}`);
        return panel.slice(start, end);
    };
    const renderSite = '<ScreenShareRequestsSection moderation={moderation}/>';
    const bodies = {
        ParticipantsPanel: slice('export function ParticipantsPanel(', 'export function HostControlsPanel('),
        HostControlsPanel: slice('export function HostControlsPanel(', 'export function MeetingInfoPanel('),
    };
    for (const [name, body] of Object.entries(bodies)) {
        assert.ok(body.includes(renderSite), `${name} must render the screen share requests section`);
        // The section shows the pending count and the approve/reject actions, so
        // the panel is only useful if it is the section that is rendered.
        assert.ok(body.includes('meeting.can_manage_screen_share_requests'), `${name} must gate the section on moderator capability`);
    }
    // Both moderation panels keep their waiting room section alongside it.
    assert.ok(bodies.ParticipantsPanel.includes('<WaitingSection moderation={moderation}/>'));
    assert.ok(bodies.HostControlsPanel.includes('<WaitingSection moderation={moderation}/>'));
});

test('the moderation poll actually loads screen share requests on the documented interval', () => {
    const moderation = source('Hooks/Meetings/useMeetingModeration.js');
    assert.match(moderation, /meeting\.can_manage_screen_share_requests \? load\('screen-share-requests', \(data\) => \{/);
    assert.match(moderation, /setScreenShareRequests\(data\.requests \|\| \[\]\)/);
    assert.match(moderation, /window\.setTimeout\(refresh, 5000\)/);
});

test('stopping or cancelling student capture consumes approval and host sharing stays direct', () => {
    const controls = source('Components/Meetings/LiveKit/MeetingControlCenter.jsx');
    assert.match(controls, /wasEnabled && !enabled.*screenShareApproval\?\.complete\(\)/);
    assert.match(controls, /onDeviceError: \(\) => .*screenShareApproval\?\.complete\(\)/);
    assert.equal(screenShareButtonState({canShareDirectly: true, requiresApproval: false, isSharing: false, requestStatus: null, requesting: false}), 'ready');
    assert.equal(shouldStartScreenCapture('ready'), true);
});

test('student requests contain no browser-trusted LiveKit identity or track SID', () => {
    const hook = source('Hooks/Meetings/useMeetingScreenShareApproval.js');
    assert.doesNotMatch(hook, /livekit_identity|track_sid/);
    assert.match(hook, /method: 'POST'/);
    assert.match(hook, /method: 'DELETE'/);
});

test('the server sharing state keeps the approval usable across a reconnect instead of forcing a new request', () => {
    const student = {canShareDirectly: false, requiresApproval: true, isSharing: false, requesting: false};
    assert.equal(screenShareButtonState({...student, requestStatus: 'sharing'}), 'approved');
    assert.equal(screenShareLabelKey(screenShareButtonState({...student, requestStatus: 'sharing'})), 'meetingRoom.screenShare.start');
    assert.equal(screenShareButtonState({...student, isSharing: true, requestStatus: 'sharing'}), 'sharing');

    // A live share is not re-requestable, but a completed one is.
    assert.equal(isActiveScreenShareRequest('pending'), true);
    assert.equal(isActiveScreenShareRequest('approved'), true);
    assert.equal(isActiveScreenShareRequest('sharing'), true);
    assert.equal(isActiveScreenShareRequest('consumed'), false);
    assert.equal(isActiveScreenShareRequest('rejected'), false);
    assert.equal(isActiveScreenShareRequest(null), false);
});

test('the hook and control center route every active server state through one shared guard', () => {
    const hook = source('Hooks/Meetings/useMeetingScreenShareApproval.js');
    assert.match(hook, /import \{isActiveScreenShareRequest\}/);
    assert.match(hook, /requesting \|\| isActiveScreenShareRequest\(current\?\.status\)/);
    assert.match(hook, /!isActiveScreenShareRequest\(current\.status\)/);
    assert.match(hook, /const status = isActiveScreenShareRequest\(current\?\.status\)/);
    assert.doesNotMatch(hook, /\['pending', 'approved'\]\.includes/);
});

test('stopping the share returns the student to the approval-required state', () => {
    // Stop share consumes the request, so the next click must request again.
    assert.equal(screenShareButtonState({canShareDirectly: false, requiresApproval: true, isSharing: false, requesting: false, requestStatus: 'consumed'}), 'request');
    assert.equal(shouldStartScreenCapture('request'), false);
    assert.equal(screenShareButtonState({canShareDirectly: false, requiresApproval: true, isSharing: true, requesting: false, requestStatus: 'consumed'}), 'sharing');
});

// ===========================================================================
// Reconciliation security matrix.
//
// A whole-file regex cannot prove WHERE a call lives: "contains reconcile"
// keeps passing the moment the call is moved into the approval handler, a
// mount effect, the camera toggle, the stop path or the device-error path.
// Every assertion below therefore slices the exact function it is about, and
// the behavioural tests execute the real production source with stubbed
// dependencies so the outcome is observed rather than pattern-matched.
// ===========================================================================

const HOOK_PATH = 'Hooks/Meetings/useMeetingScreenShareApproval.js';
const CONTROLS_PATH = 'Components/Meetings/LiveKit/MeetingControlCenter.jsx';
const hookSource = source(HOOK_PATH);
const controlsSource = source(CONTROLS_PATH);

const CLOSERS = {'{': '}', '(': ')', '[': ']'};

/** Index of the delimiter closing the one opened at `openIndex`, or -1. */
const closingIndex = (text, openIndex) => {
    const open = text[openIndex];
    const close = CLOSERS[open];
    if (!close) throw new Error(`Not an opening delimiter: ${open}`);
    let depth = 0;
    for (let index = openIndex; index < text.length; index += 1) {
        const character = text[index];
        // Comments come first: an apostrophe in prose such as "the student's
        // gesture" must not be mistaken for the start of a string literal.
        if (character === '/' && text[index + 1] === '/') {
            const newline = text.indexOf('\n', index);
            index = newline === -1 ? text.length : newline;
            continue;
        }
        if (character === '/' && text[index + 1] === '*') {
            const terminator = text.indexOf('*/', index + 2);
            index = terminator === -1 ? text.length : terminator + 1;
            continue;
        }
        // Strings and template literals are opaque, so braces inside them
        // (for example `${base}`) cannot disturb the depth count.
        if (character === "'" || character === '"' || character === '`') {
            const quote = character;
            index += 1;
            while (index < text.length && text[index] !== quote) {
                if (text[index] === '\\') index += 1;
                index += 1;
            }
            continue;
        }
        if (character === open) depth += 1;
        else if (character === close) {
            depth -= 1;
            if (depth === 0) return index;
        }
    }
    return -1;
};

/** The exact text spanning two literal markers, both asserted to exist. */
const between = (text, startMarker, endMarker) => {
    const start = text.indexOf(startMarker);
    assert.notEqual(start, -1, `${startMarker} must exist`);
    const end = text.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `${endMarker} must follow ${startMarker}`);
    return text.slice(start, end + endMarker.length);
};

/** The source of a `useCallback` arrow, excluding the doc comment above it. */
const callbackArrow = (text, declaration, parameterMarker, freeVariables) => {
    const start = text.indexOf(declaration);
    assert.notEqual(start, -1, `${declaration} must exist`);
    const arrowStart = text.indexOf(parameterMarker, start);
    assert.notEqual(arrowStart, -1, `${declaration} must declare ${parameterMarker}`);
    const close = closingIndex(text, text.indexOf('{', arrowStart));
    assert.notEqual(close, -1, `${declaration} must be balanced`);
    const arrow = text.slice(arrowStart, close + 1);
    return {
        arrow,
        // Builds the real production function with its dependencies injected.
        instantiate: (values) => new Function(...freeVariables, `return (${arrow});`)(...values),
    };
};

/** Every `useEffect(...)` argument list in a file, in source order. */
const effectSources = (text) => {
    const found = [];
    let cursor = 0;
    for (;;) {
        const start = text.indexOf('useEffect(', cursor);
        if (start === -1) break;
        const close = closingIndex(text, start + 'useEffect'.length);
        assert.notEqual(close, -1, 'every useEffect must be balanced');
        found.push(text.slice(start + 'useEffect'.length, close + 1));
        cursor = close + 1;
    }
    return found;
};

/**
 * The arrow function assigned to a JSX prop, as executable source.
 * A JSX prop wraps its value in a `{...}` expression container, so the arrow's
 * own body brace is the next one after it.
 */
const propArrow = (text, propName) => {
    const marker = `${propName}=`;
    const markerIndex = text.indexOf(marker);
    assert.notEqual(markerIndex, -1, `${propName} must exist`);
    const container = text.indexOf('{', markerIndex + marker.length);
    const body = text.indexOf('{', container + 1);
    const openParen = text.indexOf('(', markerIndex + marker.length);
    const close = closingIndex(text, body);
    assert.notEqual(close, -1, `${propName} must be balanced`);
    return text.slice(openParen, close + 1);
};

const reconcile = callbackArrow(
    hookSource,
    'const reconcile = useCallback(',
    'async () => {',
    ['enabled', 'current', 'document', 'fetch', 'base', 'onNotice', 'refresh', 'noticeKey'],
);
const refresh = callbackArrow(
    hookSource,
    'const refresh = useCallback(',
    'async () => {',
    ['enabled', 'fetch', 'base', 'onNotice', 'noticeKey', 'setCurrent', 'refreshGeneration'],
);
const screenShareChanged = callbackArrow(
    controlsSource,
    'const screenShareChanged = useCallback(',
    '(enabled, isUserInitiated) => {',
    ['screenShareWasEnabled', 'pendingApprovedShareStartRef', 'screenShareIntentChange', 'writeMeetingMediaIntent', 'mediaIntentKey', 'leavingPage', 'meeting', 'screenShareApproval'],
);
const toggleScreenShare = callbackArrow(
    controlsSource,
    'const toggleScreenShare =',
    '(event) => {',
    ['shareState', 'screenShareApproval', 'shouldStartScreenCapture', 'isScreenShareEnabled', 'writeMeetingMediaIntent', 'mediaIntentKey', 'meeting', 'pendingApprovedShareStartRef', 'share'],
);

const CSRF_TOKEN = 'csrf-token-from-the-page';
const SCHOOL_CLASS_ID = 7;
const MEETING_UUID = '3f6c1c8e-meeting';
const REQUEST_REFERENCE = 'ref uuid/1+2';
// The hook builds its own scope template, so the expected URL is rendered from
// the production template rather than from a copy written in this test.
const BASE = hookSource.match(/const base = `([^`]+)`/)[1]
    .replace('${schoolClass.id}', String(SCHOOL_CLASS_ID))
    .replace('${meeting.uuid}', MEETING_UUID);
const ENCODED_REFERENCE = encodeURIComponent(REQUEST_REFERENCE);
const RECONCILE_URL = `${BASE}/${ENCODED_REFERENCE}/reconcile`;

const stubNoticeKey = (key) => ({noticeKey: key});
const jsonResponse = (body, {ok = true, status = 200} = {}) => ({ok, status, json: async () => body});
const approvedPayload = {reference: REQUEST_REFERENCE, status: 'approved'};
const csrfDocument = {querySelector: (selector) => (selector === 'meta[name="csrf-token"]' ? {content: CSRF_TOKEN} : null)};

/**
 * Executes the real reconcile() from the hook against a scripted provider
 * response and records everything it did.
 */
const runReconcile = async (makeResponse, {enabled = true, current = approvedPayload} = {}) => {
    const calls = {fetch: [], notices: [], refreshed: 0};
    await reconcile.instantiate([
        enabled,
        current,
        csrfDocument,
        async (url, options) => {
            calls.fetch.push({url, options});
            return makeResponse(url, options);
        },
        BASE,
        (notice) => calls.notices.push(notice),
        () => { calls.refreshed += 1; },
        stubNoticeKey,
    ])();
    return calls;
};

test('reconcile posts to the authoritative route scope and sends no body at all', async () => {
    const calls = await runReconcile(() => jsonResponse({reconciled: true}));

    assert.equal(calls.fetch.length, 1, 'reconcile must issue exactly one request');
    const {url, options} = calls.fetch[0];
    // Only the route scope: class id, meeting uuid and the opaque reference.
    assert.equal(url, RECONCILE_URL);
    assert.equal(url, `/collaboration/classes/${SCHOOL_CLASS_ID}/meetings/${MEETING_UUID}/screen-share-requests/${ENCODED_REFERENCE}/reconcile`);
    assert.equal(options.method, 'POST');
    assert.equal(options.headers['X-CSRF-TOKEN'], CSRF_TOKEN);
    assert.equal(options.headers.Accept, 'application/json');
    // No body at all, so there is nothing a browser could assert as truth.
    assert.equal('body' in options, false);
    assert.deepEqual(Object.keys(options).sort(), ['headers', 'method']);
    // The approval state is not echoed back either.
    assert.equal(url.includes('approved'), false);
    assert.equal(calls.notices.length, 0);
});

test('the reconcile callback carries no provider identifier and no sharing claim', () => {
    // The doc comment above reconcile() deliberately names these fields, so a
    // whole-file check would be meaningless. Prove the scoping is real, then
    // assert the absence against the callback body alone.
    assert.match(hookSource, /No LiveKit room name, identity, participant SID, track SID/);
    for (const forbidden of [
        'livekit_room_name',
        'livekit_identity',
        'participant_sid',
        'track_sid',
        'track_source',
        'room_name',
        'is_sharing',
        'source',
    ]) {
        assert.equal(reconcile.arrow.includes(forbidden), false, `reconcile() must not reference ${forbidden}`);
    }
    // It reads nothing from the current request except the opaque reference.
    const reads = reconcile.arrow.match(/current\??\.[A-Za-z_]+/g) || [];
    assert.ok(reads.length > 0, 'reconcile must read the current request reference');
    assert.equal(reads.every((read) => read === 'current?.reference' || read === 'current.reference'), true);
    assert.equal(reconcile.arrow.includes('current.status'), false, 'reconcile must not branch on the approval status');
});

test('reconcile is inert while the meeting is not connected, live and approval-gated', async () => {
    const calls = await runReconcile(() => jsonResponse({reconciled: true}), {enabled: false});

    // Not connected, not active, or not approval-gated: the hook refuses before
    // it reads the CSRF token, so no request can be made at all.
    assert.equal(calls.fetch.length, 0);
    assert.equal(calls.notices.length, 0);
    assert.equal(calls.refreshed, 0);
});

test('an application-owned approved Start intent reconciles a later enabled publication exactly once', () => {
    const scenario = (overrides) => {
        const calls = {reconcile: 0, complete: 0, intents: []};
        const pendingApprovedShareStartRef = {current: false};
        const start = toggleScreenShare.instantiate([
            overrides.shareState ?? 'approved',
            {request: () => { calls.requested = (calls.requested || 0) + 1;}},
            shouldStartScreenCapture,
            false,
            () => {},
            'media-intent-key',
            {requires_screen_share_approval: overrides.requiresApproval ?? true},
            pendingApprovedShareStartRef,
            {buttonProps: {onClick: () => { calls.toggled = (calls.toggled || 0) + 1; }}},
        ]);
        if (overrides.start) start({type: 'click'});
        const changed = screenShareChanged.instantiate([
            {current: overrides.wasEnabled ?? false},
            pendingApprovedShareStartRef,
            (input) => { calls.intents.push(input); return null; },
            (key, intent) => { calls.intents.push({key, intent}); },
            'media-intent-key',
            {current: false},
            {requires_screen_share_approval: overrides.requiresApproval ?? true},
            {reconcile: () => { calls.reconcile += 1; }, complete: () => { calls.complete += 1; }},
        ]);
        changed(overrides.enabled, overrides.isUserInitiated);
        return calls;
    };

    // The runtime regression: LiveKit may report false after a real approved
    // Start click. The application latch still causes one reconciliation.
    const delayedPublication = scenario({start: true, enabled: true, isUserInitiated: false});
    assert.equal(delayedPublication.toggled, 1);
    assert.equal(delayedPublication.reconcile, 1);
    // An enabled observation without an approved Start click remains inert.
    assert.equal(scenario({enabled: true, isUserInitiated: false}).reconcile, 0);
    assert.equal(scenario({start: true, enabled: false, isUserInitiated: false}).reconcile, 0);
    assert.equal(scenario({start: true, enabled: true, isUserInitiated: false, requiresApproval: false}).reconcile, 0);

    assert.match(screenShareChanged.arrow, /enabled && meeting\.requires_screen_share_approval && pendingApprovedShareStartRef\.current/);
    assert.equal((screenShareChanged.arrow.match(/reconcile\(/g) || []).length, 1, 'screenShareChanged must hold the only reconcile call');
    assert.equal((controlsSource.match(/screenShareApproval\?\.reconcile\(\)/g) || []).length, 1, 'the whole file must hold exactly one reconcile call');
});

test('an intermediate disabled observation does not cancel an approved Start attempt before publication', () => {
    const calls = {reconcile: 0, complete: 0};
    const pendingApprovedShareStartRef = {current: true};
    const changed = screenShareChanged.instantiate([
        {current: false},
        pendingApprovedShareStartRef,
        () => null,
        () => {},
        'media-intent-key',
        {current: false},
        {requires_screen_share_approval: true},
        {reconcile: () => { calls.reconcile += 1; }, complete: () => { calls.complete += 1; }},
    ]);

    changed(false, false); // picker/publication is still pending
    assert.equal(pendingApprovedShareStartRef.current, true, 'a false observation is not an authoritative cancellation');
    changed(true, false);  // LiveKit later observes the real publication

    assert.equal(calls.reconcile, 1);
    assert.equal(pendingApprovedShareStartRef.current, false, 'the successful publication consumes the one-shot intent');
    assert.equal(calls.complete, 0);
    assert.doesNotMatch(screenShareChanged.arrow, /if \(!enabled\) pendingApprovedShareStartRef\.current = false/);
});

test('a reconnect or mount observation of the local track never reconciles', () => {
    // onChange observes isScreenShareEnabled from an effect, so a reconnect, a
    // reload, a resume or a remote change all arrive with isUserInitiated
    // false. None of them may ask the server to re-check the approval.
    const calls = {reconcile: 0, complete: 0};
    const changed = screenShareChanged.instantiate([
        {current: false},
        {current: false},
        () => null,
        () => {},
        'media-intent-key',
        {current: false},
        {requires_screen_share_approval: true},
        {reconcile: () => { calls.reconcile += 1; }, complete: () => { calls.complete += 1; }},
    ]);

    changed(true, false);   // client re-observes the track as enabled after a reconnect
    changed(false, false);  // track is no longer published
    changed(true, false);   // and enabled again, still without a gesture
    changed(false, false);

    assert.equal(calls.reconcile, 0, 'no non-gesture observation may reconcile');
});

test('duplicate enabled observations consume one approved Start intent only once', () => {
    const calls = {reconcile: 0, complete: 0};
    const changed = screenShareChanged.instantiate([
        {current: false},
        {current: true},
        () => null,
        () => {},
        'media-intent-key',
        {current: false},
        {requires_screen_share_approval: true},
        {reconcile: () => { calls.reconcile += 1; }, complete: () => { calls.complete += 1; }},
    ]);

    changed(true, false);
    changed(true, false);

    assert.equal(calls.reconcile, 1);
});

test('an approved request, its notice and its polling refresh never reconcile', async () => {
    // The approval notice effect, the polling effect and the Reverb effect are
    // the paths an approved state actually travels. None may reconcile.
    const effects = effectSources(hookSource);
    assert.equal(effects.length, 3, 'the hook has the refresh, Reverb and notice effects');
    for (const effect of effects) {
        assert.equal(effect.includes('reconcile'), false, 'no effect in the approval hook may reconcile');
    }
    const noticeEffect = effects[2];
    assert.match(noticeEffect, /current\.status === 'approved'\) onNotice/);
    assert.equal(refresh.arrow.includes('reconcile'), false, 'the polling refresh may not reconcile');

    // Executed for real: a refresh that observes an approved request updates
    // local state and emits the notice, and never calls the reconcile endpoint.
    const calls = {notices: [], set: []};
    const doRefresh = refresh.instantiate([
        true,
        async () => jsonResponse({current: approvedPayload, requests: []}),
        BASE,
        (notice) => calls.notices.push(notice),
        stubNoticeKey,
        (value) => calls.set.push(value),
        {current: 0},
    ]);
    await doRefresh();

    assert.equal(calls.set.length, 1);
    assert.equal(calls.set[0].status, 'approved');
    assert.equal(calls.notices.length, 0, 'a successful refresh reports nothing to the student');
});

test('approval polling bypasses caches and cannot regress Approved to an older Pending response', async () => {
    const pending = {reference: REQUEST_REFERENCE, status: 'pending'};
    const updates = [];
    const deferred = [];
    const fetch = (url, options) => new Promise((resolve) => deferred.push({url, options, resolve}));
    const refreshGeneration = {current: 0};
    const doRefresh = refresh.instantiate([
        true,
        fetch,
        BASE,
        () => {},
        stubNoticeKey,
        (value) => updates.push(value),
        refreshGeneration,
    ]);

    const olderPending = doRefresh();
    const newerApproved = doRefresh();
    assert.equal(deferred.length, 2);
    assert.equal(deferred[0].url, BASE);
    assert.equal(deferred[0].options.cache, 'no-store', 'meeting approval state must bypass browser and intermediary caches');
    assert.equal(deferred[0].options.headers.Accept, 'application/json');

    deferred[1].resolve(jsonResponse({current: approvedPayload, requests: []}));
    await newerApproved;
    deferred[0].resolve(jsonResponse({current: pending, requests: []}));
    await olderPending;

    assert.deepEqual(updates, [approvedPayload], 'a slower Pending response must not overwrite the newer Approved state');
    assert.equal(refreshGeneration.current, 2);
});

test('an approved request still waits for the student to press Start sharing', () => {
    // Approval may expose the start affordance, but reaching the server-side
    // publication check is still the student's explicit click.
    assert.equal(screenShareButtonState({canShareDirectly: false, requiresApproval: true, isSharing: false, requestStatus: 'approved'}), 'approved');
    assert.equal(screenShareLabelKey('approved'), 'meetingRoom.screenShare.start');
    assert.equal(shouldStartScreenCapture('approved'), true);

    // Approval alone starts no capture and asks for no reconciliation: the hook
    // owns no browser capture API and never calls reconcile itself. It only
    // hands the function to the caller, which must apply the gesture guard.
    assert.doesNotMatch(hookSource, /getDisplayMedia|setScreenShareEnabled/);
    assert.doesNotMatch(hookSource, /reconcile\(/, 'the hook must never call its own reconcile');
    assert.match(hookSource, /const reconcile = useCallback\(async \(\) => \{/);
    assert.match(hookSource, /return \{status, current, requesting, request, complete, reconcile, refresh\};/);
});

test('stopping the share completes the approval and never starts a reconciliation', () => {
    const calls = {reconcile: 0, complete: 0};
    const changed = screenShareChanged.instantiate([
        {current: true},
        {current: false},
        () => null,
        () => {},
        'media-intent-key',
        {current: false},
        {requires_screen_share_approval: true},
        {reconcile: () => { calls.reconcile += 1; }, complete: () => { calls.complete += 1; }},
    ]);
    changed(false, true);

    assert.equal(calls.complete, 1, 'stopping still consumes the approval');
    assert.equal(calls.reconcile, 0, 'stopping must never start a reconciliation');
    // The completion path is separate and is gated on the window closing, not
    // on a share starting.
    assert.match(screenShareChanged.arrow, /if \(wasEnabled && !enabled && !leavingPage\.current && meeting\.requires_screen_share_approval\) screenShareApproval\?\.complete\(\)/);
    assert.equal(screenShareChanged.arrow.indexOf('reconcile()') < screenShareChanged.arrow.indexOf('complete()'), true, 'the guarded reconcile precedes the completion branch');
});

test('a screen-share device error reports unavailability without reconciling', () => {
    // Executed for real: the useTrackToggle config is built from production
    // source, then onDeviceError is invoked exactly as LiveKit would.
    const calls = {reconcile: 0, complete: 0, notices: [], toggles: 0};
    const pendingApprovedShareStartRef = {current: true};
    const config = new Function('Track', 'screenShareChanged', 'pendingApprovedShareStartRef', 'onMessage', 'meeting', 'screenShareApproval', 'noticeKey', `return (${
        (() => {
            const marker = 'const share = useTrackToggle(';
            const start = controlsSource.indexOf(marker);
            assert.notEqual(start, -1, 'the screen share toggle must be configured');
            const open = controlsSource.indexOf('{', start + marker.length);
            const close = closingIndex(controlsSource, open);
            return controlsSource.slice(open, close + 1);
        })()
    });`)(
        {Source: {ScreenShare: 'screen_share'}},
        () => { calls.toggles += 1; },
        pendingApprovedShareStartRef,
        (notice) => calls.notices.push(notice),
        {requires_screen_share_approval: true},
        {reconcile: () => { calls.reconcile += 1; }, complete: () => { calls.complete += 1; }},
        stubNoticeKey,
    );

    config.onDeviceError();

    // The error path may report and clean up, but no publication ever existed,
    // so it must not claim one to the server.
    assert.equal(calls.reconcile, 0, 'a device failure must never reconcile');
    assert.equal(calls.notices.length, 1);
    assert.equal(calls.notices[0].noticeKey, 'meetingRoom.controlCenter.shareUnavailable');
    assert.equal(calls.complete, 1, 'the approval is released instead of left dangling');
    assert.equal(calls.toggles, 0);
    assert.equal(pendingApprovedShareStartRef.current, false, 'a picker cancellation or capture error must clear the pending Start intent');
    const onDeviceError = between(controlsSource, 'onDeviceError: () => {', 'if (meeting.requires_screen_share_approval) screenShareApproval?.complete(); }');
    assert.equal(onDeviceError.includes('reconcile'), false);
});

test('camera and microphone toggles never reconcile the screen share approval', () => {
    const deviceBlocks = controlsSource.match(/<div className="meeting-device-control[^"]*">[\s\S]*?<\/div>\s*<\/div>/g) || [];
    assert.equal(deviceBlocks.length, 2, 'the camera and microphone device blocks must be found');
    const [cameraBlock, microphoneBlock] = deviceBlocks;
    assert.match(cameraBlock, /Track\.Source\.Camera/);
    assert.match(microphoneBlock, /Track\.Source\.Microphone/);
    for (const [name, block] of [['camera', cameraBlock], ['microphone', microphoneBlock]]) {
        assert.equal(block.includes('reconcile'), false, `the ${name} toggle must never reconcile`);
        assert.equal(block.includes('screenShareApproval'), false, `the ${name} toggle must not touch the approval hook`);
    }

    // Executed for real: each toggle's own onChange and onDeviceError run with
    // the approval hook's reconcile stubbed in, and it is never called.
    for (const [name, block, captureKey] of [['camera', cameraBlock, 'cameraEnabled'], ['microphone', microphoneBlock, 'microphoneEnabled']]) {
        const calls = {reconcile: 0, intents: [], notices: []};
        const env = [
            (key, intent) => { calls.intents.push({key, intent}); },
            'media-intent-key',
            (notice) => calls.notices.push(notice),
            {reconcile: () => { calls.reconcile += 1; }},
            stubNoticeKey,
        ];
        const onChange = `(${propArrow(block, 'onChange')})`;
        const onDeviceError = `(${propArrow(block, 'onDeviceError')})`;
        const build = new Function('writeMeetingMediaIntent', 'mediaIntentKey', 'onMessage', 'screenShareApproval', 'noticeKey', `return [${onChange}, ${onDeviceError}];`);
        const [change, error] = build(...env);

        change(true, true);
        change(false, true);
        error();

        assert.equal(calls.reconcile, 0, `the ${name} toggle must never reconcile`);
        // The device toggle only records its own intent.
        assert.equal(calls.intents.every((entry) => entry.intent !== undefined || entry.captureKey === captureKey), true);
    }
});

test('no mount, reconnect or polling effect reconciles automatically', () => {
    // The hook's effects, and the control centre's own effects, are all
    // inspected: none of them may reconcile on mount, on reconnect or on
    // resume, so a restored Approved state still requires a fresh click.
    for (const effect of effectSources(hookSource)) {
        assert.equal(effect.includes('reconcile'), false);
    }
    assert.equal(effectSources(hookSource)[0].includes('setInterval(refresh, 15000)'), true, 'the hook still polls the request list');
    for (const effect of effectSources(controlsSource)) {
        assert.equal(effect.includes('reconcile'), false, 'no control-centre effect may reconcile');
    }
    // The resume affordance only relabels the button; it captures nothing.
    const resumeState = between(controlsSource, 'useState(() => readMeetingMediaIntent(mediaIntentKey)', 'useRef(isScreenShareEnabled)');
    assert.equal(resumeState.includes('reconcile'), false);
    assert.equal(resumeState.includes('getDisplayMedia'), false);
    // Restoring an approval across a refresh leaves the student on Start.
    assert.equal(screenShareButtonState({canShareDirectly: false, requiresApproval: true, isSharing: false, requestStatus: 'approved'}), 'approved');
    assert.equal(screenShareLabelKey('approved'), 'meetingRoom.screenShare.start');
    assert.equal(shouldStartScreenCapture('approved'), true);
});

test('a lapsed publication is answered as a settled outcome, never a client retry', async () => {
    // The server answers a lapsed publication with 200 {reconciled:false,
    // retrying:false} and dispatches no retry. The client must treat that as a
    // finished answer, not as something to try again.
    const calls = await runReconcile(() => jsonResponse({reconciled: false, retrying: false}));

    assert.equal(calls.fetch.length, 1, 'a settled answer must not be re-posted');
    assert.equal(calls.notices.length, 0, 'a settled answer is not a failure');
    assert.equal(calls.refreshed, 0, 'nothing changed, so nothing is re-read');
    // No client timer, no recursion, no local teardown of a live share.
    assert.doesNotMatch(reconcile.arrow, /setTimeout|setInterval|requestAnimationFrame/);
    assert.equal(reconcile.arrow.includes('complete('), false, 'reconcile never stops the local share');
    assert.equal(reconcile.arrow.includes('retrying'), false, 'the client ignores the retrying flag entirely');
    assert.doesNotMatch(hookSource, /reconcile\(/, 'the hook must never call its own reconcile');
});

test('a 202 handoff is accepted as success and defers to the bounded server retry', async () => {
    const calls = await runReconcile(() => jsonResponse({reconciled: false, retrying: true}, {ok: true, status: 202}));

    // 202 is ok, so it is not an error and must not raise the failure notice.
    assert.equal(calls.notices.length, 0, 'a 202 handoff is not a client error');
    assert.equal(calls.fetch.length, 1, 'the client must not add a second attempt of its own');
    assert.equal(calls.refreshed, 0);
    // Convergence belongs to the server retry, the poll and the Reverb refresh.
    assert.match(hookSource, /window\.setInterval\(refresh, 15000\)/);
    assert.match(hookSource, /channel\.listen\('\.meeting\.screen-share-request-changed', changed\)/);
});

test('a non-OK reconcile response surfaces the existing notice and stops there', async () => {
    // The one existing notice path, so the student learns the share is not yet
    // confirmed while their screen keeps going.
    const calls = await runReconcile(() => jsonResponse({message: 'Server error.'}, {ok: false, status: 500}));

    assert.equal(calls.notices.length, 1);
    assert.equal(calls.notices[0].noticeKey, 'meetingRoom.screenShare.reconcileFailed');
    // One attempt only, and nothing else is touched: the local share is not
    // disabled, the approval is not completed, and no LiveKit metadata is
    // re-sent anywhere.
    assert.equal(calls.fetch.length, 1, 'a failure must not be retried by the client');
    assert.equal(calls.refreshed, 0);
    assert.equal(reconcile.arrow.includes('complete('), false);
    assert.equal(reconcile.arrow.includes('setScreenShareEnabled'), false);
    assert.equal(calls.fetch[0].options.method, 'POST');
    assert.equal('body' in calls.fetch[0].options, false);

    // A response that cannot even be decoded degrades to no notice at all,
    // because the request itself succeeded.
    const undecodable = await runReconcile(() => ({ok: true, status: 200, json: async () => { throw new Error('invalid body'); }}));
    assert.equal(undecodable.notices.length, 0);
    assert.equal(undecodable.fetch.length, 1);
});

test('both locales resolve the reconcile failure notice', () => {
    const key = 'meetingRoom.screenShare.reconcileFailed';
    const english = resolveKey(en, key);
    const khmer = resolveKey(km, key);

    for (const [locale, value] of [['en', english], ['km', khmer]]) {
        assert.equal(typeof value, 'string', `${locale} must define ${key}`);
        assert.ok(value.trim().length > 0, `${locale} must not leave ${key} empty`);
    }
    // A real translation, not an English copy left in the Khmer catalogue.
    assert.notEqual(english, khmer);
    // The notice the error path raises is the one the catalogues define.
    assert.equal(reconcile.arrow.includes(`noticeKey('${key}')`), true);
    // The approved-state notice beside it is present in both too.
    assert.equal(typeof resolveKey(en, 'meetingRoom.screenShare.approved'), 'string');
    assert.equal(typeof resolveKey(km, 'meetingRoom.screenShare.approved'), 'string');
});

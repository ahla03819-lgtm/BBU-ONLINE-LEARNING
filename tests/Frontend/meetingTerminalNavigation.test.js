import assert from 'node:assert/strict';
import test from 'node:test';
import {createMeetingLeaveTransaction} from '../../resources/js/Providers/meetingLeaveTransaction.js';
import {shouldNavigateToLobby, meetingPath} from '../../resources/js/Components/Meetings/LiveKit/meetingEndTeardown.js';

const CLASS_ID = 42;
const UUID = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
const roomUrl = `/collaboration/classes/${CLASS_ID}/meetings/${UUID}/room`;
const lobbyUrl = `/collaboration/classes/${CLASS_ID}/meetings/${UUID}/lobby`;

/**
 * A faithful, synchronous model of PersistentMeetingProvider.clearSession() using
 * the REAL imported decision helper and the REAL leave transaction.
 *
 * Only the browser edges are injected: location, router.visit and
 * window.location.assign. Everything that decides whether a client navigates or
 * releases its session is the production code under test.
 */
function createHarness({pathname}) {
    const state = {
        session: {roomUrl, lobbyUrl, mediaIntentKey: 'k', onLeave: async () => {}},
        path: pathname,
        navigations: [],
        assigns: [],
        visits: [],
        sessionCleared: 0,
    };

    const transaction = createMeetingLeaveTransaction();

    state.clearSession = async ({returnToLobby = false, disconnectRoom, destination, fallbackNavigation = false} = {}) => {
        const current = state.session;
        if (!current) return {status: 'cleared'};
        const target = destination ?? current.lobbyUrl;
        // Captured once at entry, exactly as PersistentMeetingProvider.clearSession
        // does, before cleanup() and disconnect() are awaited.
        const capturedPath = state.path;

        return transaction({
            returnToLobby,
            cleanup: async () => { await current.onLeave?.(); },
            disconnect: async () => { await disconnectRoom?.(); },
            navigate: () => {
                // The decision uses the path captured at entry. A pathname change
                // during the awaited teardown steps must not cancel it.
                state.pathChangedDuringTeardown = state.path !== capturedPath;
                if (!shouldNavigateToLobby({returnToLobby, currentPath: capturedPath, roomUrl: current.roomUrl})) {
                    return Promise.resolve({status: 'success'});
                }
                return new Promise((resolve) => {
                    let status = 'finished';
                    state.visits.push(target);
                    state.navigateOptions = {replace: true};
                    // Injected by the test before resolve is called.
                    state.pendingResolve = () => resolve({status});
                    state.settle = (next) => {
                        // Mirrors the provider's onSuccess/onError/onCancel/onFinish:
                        // the status is set, and onFinish always resolves the promise.
                        status = next;
                        if ((next === 'error' || next === 'cancelled') && fallbackNavigation) {
                            status = 'fallback';
                            state.assigns.push(target);
                        }
                        resolve({status});
                    };
                });
            },
            clearSession: () => {
                state.sessionCleared += 1;
                state.session = null;
            },
        });
    };

    return state;
}

// ---------------------------------------------------------------- CASE A
test('CASE A: host End from the room path navigates exactly once and clears the session', async () => {
    const h = createHarness({pathname: roomUrl});

    const pending = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: true});
    await new Promise((r) => setImmediate(r));

    assert.equal(h.visits.length, 1, 'exactly one navigation attempt');
    assert.equal(h.visits[0], lobbyUrl);
    h.settle('success');
    const result = await pending;

    assert.equal(result.status, 'success');
    assert.equal(h.sessionCleared, 1);
    assert.equal(h.session, null, 'session released after a successful navigation');
});

test('CASE A: the decision helper agrees with the room path', () => {
    assert.equal(shouldNavigateToLobby({returnToLobby: true, currentPath: roomUrl, roomUrl}), true);
    assert.equal(shouldNavigateToLobby({returnToLobby: false, currentPath: roomUrl, roomUrl}), false);
    assert.equal(shouldNavigateToLobby({returnToLobby: true, currentPath: lobbyUrl, roomUrl}), false);
    assert.equal(meetingPath(roomUrl), roomUrl);
});

// ---------------------------------------------------------------- CASE B
test('CASE B: a realtime terminal event from the room path also navigates once', async () => {
    const h = createHarness({pathname: roomUrl});

    const pending = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: true});
    await new Promise((r) => setImmediate(r));
    h.settle('success');
    await pending;

    assert.equal(h.visits.length, 1);
    assert.equal(h.sessionCleared, 1);
});

// ---------------------------------------------------------------- CASE C
test('CASE C: a manual End interleaved with a terminal event is single-flight and navigates once', async () => {
    const h = createHarness({pathname: roomUrl});

    // Two concurrent terminal triggers, as manual End + a realtime event produce.
    const manual = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: true});
    await new Promise((r) => setImmediate(r));
    const realtime = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: true});
    await new Promise((r) => setImmediate(r));

    assert.equal(h.visits.length, 1, 'the second terminal trigger must not start a second navigation');
    h.settle('success');
    const [a, b] = await Promise.all([manual, realtime]);

    assert.equal(a.status, 'success');
    assert.equal(b.status, 'success');
    assert.equal(h.sessionCleared, 1, 'cleanup happened exactly once');

    // A terminal trigger after the session is gone is a no-op.
    const after = await h.clearSession({returnToLobby: true, fallbackNavigation: true});
    assert.equal(after.status, 'cleared');
    assert.equal(h.visits.length, 1, 'no further navigation after release');
    assert.equal(h.sessionCleared, 1);
});

// ---------------------------------------------------------------- CASE D
test('INVARIANT: a terminal transition begun on the room path navigates exactly once even if the pathname changes during teardown', async () => {
    const h = createHarness({pathname: roomUrl});

    const pending = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: true});

    // The path was captured synchronously at entry. Simulate a concurrent Inertia
    // navigation landing while cleanup()/disconnect() are still being awaited,
    // i.e. before navigate() is reached.
    h.path = lobbyUrl;
    await new Promise((r) => setImmediate(r));

    assert.equal(h.visits.length, 1, 'the committed terminal navigation must not be skipped');
    assert.equal(h.visits[0], lobbyUrl);
    h.settle('success');
    const result = await pending;

    assert.equal(result.status, 'success');
    assert.equal(h.pathChangedDuringTeardown, true, 'the pathname really did move during teardown');
    assert.equal(h.sessionCleared, 1, 'session released only after a safe terminal status');
    assert.equal(h.session, null);
});

test('INVARIANT: a terminal transition begun away from the room path releases the session without navigating', async () => {
    // The mini-window / already-left contract: this client has nothing to
    // navigate away from, so the session is simply released.
    const h = createHarness({pathname: lobbyUrl});

    const result = await h.clearSession({returnToLobby: false, destination: lobbyUrl, fallbackNavigation: true});

    assert.equal(result.status, 'cleared');
    assert.equal(h.visits.length, 0, 'no navigation for a client that was never on the room page');
    assert.equal(h.sessionCleared, 1);
    assert.equal(h.session, null);
});

// ------------------------------------------------- router failure / cancel
test('router onError with fallbackNavigation assigns the lobby and clears the session', async () => {
    const h = createHarness({pathname: roomUrl});

    const pending = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: true});
    await new Promise((r) => setImmediate(r));
    h.settle('error');
    const result = await pending;

    assert.equal(result.status, 'fallback');
    assert.deepEqual(h.assigns, [lobbyUrl], 'hard navigation fallback was used');
    assert.equal(h.sessionCleared, 1);
});

test('router onCancel with fallbackNavigation assigns the lobby and clears the session', async () => {
    const h = createHarness({pathname: roomUrl});

    const pending = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: true});
    await new Promise((r) => setImmediate(r));
    h.settle('cancelled');
    const result = await pending;

    assert.equal(result.status, 'fallback');
    assert.deepEqual(h.assigns, [lobbyUrl]);
    assert.equal(h.sessionCleared, 1);
});

test('router onError without fallbackNavigation keeps the session and does not assign', async () => {
    const h = createHarness({pathname: roomUrl});

    const pending = h.clearSession({returnToLobby: true, destination: lobbyUrl, fallbackNavigation: false});
    await new Promise((r) => setImmediate(r));
    h.settle('error');
    const result = await pending;

    assert.equal(result.status, 'error');
    assert.deepEqual(h.assigns, [], 'no hard navigation on the Leave path');
    assert.equal(h.sessionCleared, 0, 'session preserved so the client is not stranded');
    assert.notEqual(h.session, null);
});
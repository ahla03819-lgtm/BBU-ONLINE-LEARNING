import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';
import test from 'node:test';
import {canPollLobbyWaitingRoom, LOBBY_WAITING_ROOM_POLL_INTERVAL, startLobbyWaitingRoomPolling} from '../../resources/js/Pages/Meetings/lobbyWaitingRoomPolling.js';

const flush = async () => {
    await Promise.resolve();
    await Promise.resolve();
    await Promise.resolve();
};

const response = (status, body = {requests: []}) => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => body,
});

function timers() {
    const scheduled = [];

    return {
        scheduled,
        set(callback, delay) {
            const timer = {callback, delay, cleared: false};
            scheduled.push(timer);
            return timer;
        },
        clear(timer) { timer.cleared = true; },
        async runNext() {
            const timer = scheduled.find((candidate) => !candidate.cleared && !candidate.ran);
            assert.ok(timer, 'a pending timer exists');
            timer.ran = true;
            await timer.callback();
        },
    };
}

test('Lobby polling capability requires an explicit active management permission', () => {
    assert.equal(canPollLobbyWaitingRoom({status: 'active', can_manage_join_requests: true}), true);
    assert.equal(canPollLobbyWaitingRoom({status: 'active', can_manage_join_requests: false}), false);
    assert.equal(canPollLobbyWaitingRoom({status: 'ended', can_manage_join_requests: true}), false);
});

test('authorized polling fetches immediately, then continues every five seconds after successful responses', async () => {
    const clock = timers();
    const requests = [];
    let fetches = 0;
    const stop = startLobbyWaitingRoomPolling({
        url: '/waiting-room/requests',
        fetchImpl: async () => response(200, {requests: [{reference: `request-${++fetches}`}] }),
        onRequests: (data) => requests.push(data.requests),
        onAuthorizationFailure: () => assert.fail('must not authorize-fail'),
        onSessionFailure: () => assert.fail('must not session-fail'),
        onTemporaryFailure: () => assert.fail('must not temporarily fail'),
        setTimeoutImpl: clock.set,
        clearTimeoutImpl: clock.clear,
    });

    await flush();
    assert.equal(fetches, 1);
    assert.deepEqual(requests, [[{reference: 'request-1'}]]);
    assert.equal(clock.scheduled[0].delay, LOBBY_WAITING_ROOM_POLL_INTERVAL);

    await clock.runNext();
    await flush();
    assert.equal(fetches, 2);
    assert.deepEqual(requests.at(-1), [{reference: 'request-2'}]);
    stop();
});

test('HTTP 403 clears the current lifecycle without scheduling another privileged request', async () => {
    const clock = timers();
    let unauthorized = 0;
    const stop = startLobbyWaitingRoomPolling({
        url: '/waiting-room/requests',
        fetchImpl: async () => response(403),
        onRequests: () => assert.fail('must not publish privileged data'),
        onAuthorizationFailure: () => { unauthorized += 1; },
        onSessionFailure: () => assert.fail('must not session-fail'),
        onTemporaryFailure: () => assert.fail('must not retry a 403'),
        setTimeoutImpl: clock.set,
        clearTimeoutImpl: clock.clear,
    });

    await flush();
    assert.equal(unauthorized, 1);
    assert.equal(clock.scheduled.length, 0);
    stop();
});

test('HTTP 401 stops polling and reports a session failure without a retry loop', async () => {
    const clock = timers();
    let sessionFailures = 0;
    const stop = startLobbyWaitingRoomPolling({
        url: '/waiting-room/requests',
        fetchImpl: async () => response(401),
        onRequests: () => assert.fail('must not publish privileged data'),
        onAuthorizationFailure: () => assert.fail('must not authorization-fail'),
        onSessionFailure: () => { sessionFailures += 1; },
        onTemporaryFailure: () => assert.fail('must not retry a 401'),
        setTimeoutImpl: clock.set,
        clearTimeoutImpl: clock.clear,
    });

    await flush();
    assert.equal(sessionFailures, 1);
    assert.equal(clock.scheduled.length, 0);
    stop();
});

test('a pending request cannot overlap or update state after cleanup', async () => {
    const clock = timers();
    let resolveFetch;
    let requestSignal;
    let published = 0;
    const stop = startLobbyWaitingRoomPolling({
        url: '/waiting-room/requests',
        fetchImpl: (_url, {signal}) => {
            requestSignal = signal;
            return new Promise((resolveFetchValue) => { resolveFetch = resolveFetchValue; });
        },
        onRequests: () => { published += 1; },
        onAuthorizationFailure: () => assert.fail('must not authorization-fail'),
        onSessionFailure: () => assert.fail('must not session-fail'),
        onTemporaryFailure: () => assert.fail('must not temporarily fail'),
        setTimeoutImpl: clock.set,
        clearTimeoutImpl: clock.clear,
    });

    await flush();
    assert.equal(clock.scheduled.length, 0, 'no next request is scheduled while one is pending');
    stop();
    assert.equal(requestSignal.aborted, true, 'cleanup aborts the active request');
    resolveFetch(response(200, {requests: [{reference: 'stale'}]}));
    await flush();
    assert.equal(published, 0, 'a stale response cannot update the unmounted lobby');
    assert.equal(clock.scheduled.length, 0, 'cleanup leaves no timer behind');
});

test('temporary failures retry with bounded backoff and return to the normal cadence after recovery', async () => {
    const clock = timers();
    const outcomes = [response(500), response(500), response(200, {requests: []})];
    let temporaryFailures = 0;
    const stop = startLobbyWaitingRoomPolling({
        url: '/waiting-room/requests',
        fetchImpl: async () => outcomes.shift(),
        onRequests: () => {},
        onAuthorizationFailure: () => assert.fail('must not authorization-fail'),
        onSessionFailure: () => assert.fail('must not session-fail'),
        onTemporaryFailure: () => { temporaryFailures += 1; },
        setTimeoutImpl: clock.set,
        clearTimeoutImpl: clock.clear,
    });

    await flush();
    assert.equal(temporaryFailures, 1);
    assert.equal(clock.scheduled[0].delay, LOBBY_WAITING_ROOM_POLL_INTERVAL);

    await clock.runNext();
    await flush();
    assert.equal(temporaryFailures, 2);
    assert.equal(clock.scheduled[1].delay, LOBBY_WAITING_ROOM_POLL_INTERVAL * 2);

    await clock.runNext();
    await flush();
    assert.equal(clock.scheduled[2].delay, LOBBY_WAITING_ROOM_POLL_INTERVAL);
    stop();
});

test('the real Lobby uses the lifecycle helper and clears privileged data on permanent failures', () => {
    const source = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Meetings/Lobby.jsx'), 'utf8');

    assert.match(source, /canPollLobbyWaitingRoom\(meeting\)/);
    assert.match(source, /startLobbyWaitingRoomPolling\(/);
    assert.match(source, /onAuthorizationFailure:/);
    assert.match(source, /onSessionFailure:/);
    assert.match(source, /setPendingRequests\(\[\]\)/);
});

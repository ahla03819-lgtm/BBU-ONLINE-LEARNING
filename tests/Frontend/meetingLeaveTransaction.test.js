import assert from 'node:assert/strict';
import test from 'node:test';
import {createMeetingLeaveTransaction} from '../../resources/js/Providers/meetingLeaveTransaction.js';

test('a full-meeting leave is single-flight and clears only after a successful lobby navigation', async () => {
    const leave = createMeetingLeaveTransaction();
    const calls = [];
    let completeNavigation;
    const operation = {
        returnToLobby: true,
        cleanup: async () => { calls.push('cleanup'); },
        disconnect: async () => { calls.push('disconnect'); },
        navigate: () => {
            calls.push('navigate');
            return new Promise((resolve) => { completeNavigation = resolve; });
        },
        clearSession: () => { calls.push('clear'); },
    };

    const first = leave(operation);
    const duplicate = leave(operation);
    assert.equal(first, duplicate);
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(calls, ['cleanup', 'disconnect', 'navigate']);

    completeNavigation({status: 'success'});
    assert.deepEqual(await first, {status: 'success'});
    assert.deepEqual(calls, ['cleanup', 'disconnect', 'navigate', 'clear']);
});

test('a cancelled lobby navigation keeps the session, preventing a room resume race', async () => {
    const leave = createMeetingLeaveTransaction();
    const calls = [];
    const result = await leave({
        returnToLobby: true,
        cleanup: async () => { calls.push('cleanup'); },
        disconnect: async () => { calls.push('disconnect'); },
        navigate: async () => ({status: 'cancelled'}),
        clearSession: () => { calls.push('clear'); },
    });

    assert.deepEqual(result, {status: 'cancelled'});
    assert.deepEqual(calls, ['cleanup', 'disconnect']);
});

test('a mini-meeting leave clears locally without navigating away from the underlying page', async () => {
    const leave = createMeetingLeaveTransaction();
    const calls = [];
    const result = await leave({
        returnToLobby: false,
        cleanup: async () => { calls.push('cleanup'); },
        disconnect: async () => { calls.push('disconnect'); },
        navigate: async () => { calls.push('navigate'); return {status: 'success'}; },
        clearSession: () => { calls.push('clear'); },
    });

    assert.deepEqual(result, {status: 'cleared'});
    assert.deepEqual(calls, ['cleanup', 'disconnect', 'clear']);
});

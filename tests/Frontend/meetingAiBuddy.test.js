import assert from 'node:assert/strict';
import test from 'node:test';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {createBuddyMachine, BUDDY_STATES, isBuddyState, isValidBuddyTransition} from '../../resources/js/Components/Meetings/AI/useBBUBuddyState.js';

const STATES = ['idle', 'listening', 'transcribing', 'translating', 'thinking', 'taking_notes', 'success', 'warning', 'sleeping'];

// The state machine uses snake_case identifiers, but the i18n catalogue stores
// the `taking_notes` label under the camelCase key `takingNotes`. Map the two
// so the test can walk the machine states and look up their labels.
const LOCALE_KEY = {
    idle: 'idle',
    listening: 'listening',
    transcribing: 'transcribing',
    translating: 'translating',
    thinking: 'thinking',
    taking_notes: 'takingNotes',
    success: 'success',
    warning: 'warning',
    sleeping: 'sleeping',
};

test('the state machine exposes exactly the nine required states', () => {
    assert.deepEqual([...BUDDY_STATES], STATES);
});

test('every required state is recognised by isBuddyState', () => {
    for (const state of STATES) {
        assert.equal(isBuddyState(state), true, `${state} should be a valid buddy state`);
    }
    assert.equal(isBuddyState('unknown'), false);
    assert.equal(isBuddyState(''), false);
    assert.equal(isBuddyState(null), false);
});

test('every transition between distinct states is valid', () => {
    for (const from of STATES) {
        for (const to of STATES) {
            if (from === to) continue;
            assert.equal(isValidBuddyTransition(from, to), true, `${from} -> ${to} should be allowed`);
        }
    }
});

test('self transitions are not allowed', () => {
    for (const state of STATES) {
        assert.equal(isValidBuddyTransition(state, state), false, `${state} -> ${state} should be rejected`);
    }
});

test('invalid targets are rejected', () => {
    assert.equal(isValidBuddyTransition('idle', 'bogus'), false);
    assert.equal(isValidBuddyTransition('bogus', 'idle'), false);
});

test('the machine starts in the requested initial state', () => {
    const machine = createBuddyMachine({initialState: 'sleeping'});
    assert.equal(machine.state, 'sleeping');
});

test('an unknown initial state throws', () => {
    assert.throws(() => createBuddyMachine({initialState: 'bogus'}), /unknown initial state/);
});

test('set throws on an unknown state', () => {
    const machine = createBuddyMachine();
    assert.throws(() => machine.set('bogus'), /unknown state/);
});

test('set is a no-op when the target equals the current state', () => {
    const machine = createBuddyMachine({initialState: 'idle'});
    let called = 0;
    machine.subscribe(() => { called++; });
    machine.set('idle');
    assert.equal(called, 0, 'no transition, no listener call');
    assert.equal(machine.state, 'idle');
});

test('set moves the machine and notifies listeners', () => {
    const machine = createBuddyMachine({initialState: 'idle'});
    const events = [];
    machine.subscribe((next, prev) => events.push({next, prev}));
    machine.set('listening');
    assert.equal(machine.state, 'listening');
    assert.deepEqual(events, [{next: 'listening', prev: 'idle'}]);
});

test('transition throws on an invalid move', () => {
    const machine = createBuddyMachine({initialState: 'idle'});
    assert.throws(() => machine.transition('bogus'), /invalid transition/);
});

test('reset returns the machine to idle from any state', () => {
    const machine = createBuddyMachine({initialState: 'thinking'});
    machine.reset();
    assert.equal(machine.state, 'idle');
});

test('subscribe notifies listeners of state changes and returns an unsubscribe fn', () => {
    const machine = createBuddyMachine({initialState: 'idle'});
    const calls = [];
    const off = machine.subscribe((next, prev) => calls.push({next, prev}));
    machine.set('transcribing');
    off();
    machine.set('translating');
    assert.deepEqual(calls, [{next: 'transcribing', prev: 'idle'}]);
});

test('onStateChange is invoked on every transition', () => {
    const events = [];
    const machine = createBuddyMachine({initialState: 'idle', onStateChange: (next, prev) => events.push({next, prev})});
    machine.set('listening');
    machine.set('thinking');
    assert.deepEqual(events, [
        {next: 'listening', prev: 'idle'},
        {next: 'thinking', prev: 'listening'},
    ]);
});

test('every state label exists in both locales', () => {
    for (const state of STATES) {
        const key = LOCALE_KEY[state];
        const enLabel = en.meetingRoom.ai.states[key];
        const kmLabel = km.meetingRoom.ai.states[key];
        assert.ok(typeof enLabel === 'string' && enLabel.length > 0, `en label for ${state} (${key}) missing`);
        assert.ok(typeof kmLabel === 'string' && kmLabel.length > 0, `km label for ${state} (${key}) missing`);
    }
});

test('the buddy label exists in both locales', () => {
    assert.equal(typeof en.meetingRoom.ai.buddy, 'string');
    assert.equal(typeof km.meetingRoom.ai.buddy, 'string');
    assert.ok(en.meetingRoom.ai.buddy.length > 0);
    assert.ok(km.meetingRoom.ai.buddy.length > 0);
});

test('the assistant label exists in both locales', () => {
    assert.equal(typeof en.meetingRoom.ai.assistant, 'string');
    assert.equal(typeof km.meetingRoom.ai.assistant, 'string');
});
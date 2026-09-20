import assert from 'node:assert/strict';
import test from 'node:test';
import {sharedMeetingElapsedSeconds} from '../../resources/js/Components/Meetings/LiveKit/meetingElapsedTime.js';

test('two participants see the same elapsed time despite different local wall clocks and joining later', () => {
    const meeting = {status: 'active', session_started_at: '2026-09-20T10:00:00Z'};
    const earlyJoin = {serverNowAt: '2026-09-20T10:02:00Z', receivedAt: 100};
    const lateJoin = {serverNowAt: '2026-09-20T10:03:00Z', receivedAt: 5000};

    assert.equal(sharedMeetingElapsedSeconds(meeting, earlyJoin, 60100), 180);
    assert.equal(sharedMeetingElapsedSeconds(meeting, lateJoin, 5000), 180);
});

test('the shared clock survives a reconnect and a full-to-mini presentation change', () => {
    const meeting = {status: 'active', session_started_at: '2026-09-20T10:00:00Z'};
    const clock = {serverNowAt: '2026-09-20T10:00:10Z', receivedAt: 1000};

    assert.equal(sharedMeetingElapsedSeconds(meeting, clock, 1000), 10);
    assert.equal(sharedMeetingElapsedSeconds(meeting, clock, 61000), 70);
});

test('legacy and invalid session clocks retain the safe local timer fallback', () => {
    const clock = {serverNowAt: '2026-09-20T10:00:10Z', receivedAt: 1000};

    assert.equal(sharedMeetingElapsedSeconds({status: 'active', session_started_at: null}, clock, 2000), null);
    assert.equal(sharedMeetingElapsedSeconds({status: 'active', session_started_at: 'invalid'}, clock, 2000), null);
    assert.equal(sharedMeetingElapsedSeconds({status: 'active', session_started_at: '2026-09-20T10:01:00Z'}, clock, 2000), null);
    assert.equal(sharedMeetingElapsedSeconds({status: 'ended', session_started_at: '2026-09-20T10:00:00Z'}, clock, 2000), null);
    assert.equal(sharedMeetingElapsedSeconds({status: 'active', session_started_at: '2026-09-20T10:00:00Z'}, {...clock, receivedAt: NaN}, 2000), null);
});

test('a monotonic read before the response anchor cannot produce a negative duration', () => {
    const meeting = {status: 'active', session_started_at: '2026-09-20T10:00:00Z'};
    const clock = {serverNowAt: '2026-09-20T10:00:00Z', receivedAt: 1000};

    assert.equal(sharedMeetingElapsedSeconds(meeting, clock, 990), 0);
});

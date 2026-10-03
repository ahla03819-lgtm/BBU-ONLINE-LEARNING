import assert from 'node:assert/strict';
import test from 'node:test';
import {
    EXPLICIT_LEAVE_CAUSE,
    SILENT_DEPARTURE_CAUSES,
    recordingEndpointsFor,
    shouldSignalExplicitLeave,
} from '../../resources/js/Components/Meetings/LiveKit/meetingRecordingEndpoints.js';

const CLASS_ID = 42;
const UUID = 'a1b2c3d4-0000-4000-8000-abcdefabcdef';
const schoolClass = {id: CLASS_ID, name: 'Computer Class', section: 'A'};
const meeting = {uuid: UUID, status: 'active'};

test('a direct room load derives the recording endpoints it needs', () => {
    // Landing straight on the room URL produces a session with no lobby-supplied URLs.
    const endpoints = recordingEndpointsFor({schoolClass, meeting});

    assert.equal(endpoints.recordingUrl, `/collaboration/classes/${CLASS_ID}/meetings/${UUID}/recordings`);
    assert.equal(endpoints.leaveUrl, `/collaboration/classes/${CLASS_ID}/meetings/${UUID}/leave`);
});

test('a direct room load can therefore poll the recording state', () => {
    // Without a recording URL the poll never runs, so a participant who deep-links in
    // would never see the recording indicator.
    const {recordingUrl} = recordingEndpointsFor({schoolClass, meeting});

    assert.ok(recordingUrl);
    assert.ok(recordingUrl.endsWith('/recordings'));
});

test('a direct room load can therefore announce an explicit leave', () => {
    // Without a leave URL a recording this person started would keep running after
    // they walked out of the meeting.
    const {leaveUrl} = recordingEndpointsFor({schoolClass, meeting});

    assert.ok(leaveUrl);
    assert.ok(leaveUrl.endsWith('/leave'));
});

test('the lobby path still works and keeps its own values authoritative', () => {
    const lobbyUrls = {
        recordingUrl: '/collaboration/classes/7/meetings/lobby-provided/recordings',
        leaveUrl: '/collaboration/classes/7/meetings/lobby-provided/leave',
    };

    assert.deepEqual(recordingEndpointsFor({schoolClass, meeting, ...lobbyUrls}), lobbyUrls);
});

test('a partial lobby value still falls back for the missing endpoint', () => {
    const endpoints = recordingEndpointsFor({schoolClass, meeting, leaveUrl: '/custom/leave'});

    assert.equal(endpoints.leaveUrl, '/custom/leave');
    assert.equal(endpoints.recordingUrl, `/collaboration/classes/${CLASS_ID}/meetings/${UUID}/recordings`);
});

test('nothing is invented when the meeting cannot be identified', () => {
    // A half-built path would request the wrong meeting, so nothing is offered.
    for (const input of [
        {},
        {schoolClass, meeting: {}},
        {schoolClass: {}, meeting},
        {schoolClass: {id: 0}, meeting},
        {schoolClass, meeting: {uuid: ''}},
        {schoolClass: {id: 'abc'}, meeting},
    ]) {
        assert.deepEqual(recordingEndpointsFor(input), {recordingUrl: null, leaveUrl: null}, JSON.stringify(input));
    }
});

test('a reconnect does not become an explicit leave', () => {
    assert.equal(shouldSignalExplicitLeave('reconnect'), false);
    assert.equal(shouldSignalExplicitLeave('refresh'), false);
});

test('only a deliberate departure is announced', () => {
    assert.equal(shouldSignalExplicitLeave(EXPLICIT_LEAVE_CAUSE), true);
    assert.equal(shouldSignalExplicitLeave('explicit-leave'), true);
});

test('every silent departure cause stays silent', () => {
    for (const cause of SILENT_DEPARTURE_CAUSES) {
        assert.equal(shouldSignalExplicitLeave(cause), false, `${cause} must not stop a recording`);
    }
});

test('a full-to-mini switch never stops a recording', () => {
    assert.ok(SILENT_DEPARTURE_CAUSES.includes('full-mini-toggle'));
    assert.equal(shouldSignalExplicitLeave('full-mini-toggle'), false);
});

test('an unknown cause is treated as silent rather than as a departure', () => {
    // Failing closed here matters: announcing a departure the person never asked for
    // would end a recording for no reason.
    for (const cause of [undefined, null, '', 'something-new', 0]) {
        assert.equal(shouldSignalExplicitLeave(cause), false);
    }
});

test('the endpoints are stable, so a reconnect reuses the same recording API', () => {
    const first = recordingEndpointsFor({schoolClass, meeting});
    const afterReconnect = recordingEndpointsFor({schoolClass, meeting: {...meeting, lifecycle_version: 9}});

    assert.deepEqual(first, afterReconnect);
});

test('the recording path matches the routes the server actually exposes', () => {
    const {recordingUrl, leaveUrl} = recordingEndpointsFor({schoolClass, meeting});

    assert.match(recordingUrl, /^\/collaboration\/classes\/\d+\/meetings\/[0-9a-f-]+\/recordings$/);
    assert.match(leaveUrl, /^\/collaboration\/classes\/\d+\/meetings\/[0-9a-f-]+\/leave$/);
});

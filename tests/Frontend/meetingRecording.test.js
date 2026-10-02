import assert from 'node:assert/strict';
import test from 'node:test';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {
    formatRecordingClock,
    recordingBadgeSeconds,
    recordingElapsedSeconds,
    recordingLive,
    recordingRemainingSeconds,
    recordingSettling,
    startRecordingControlState,
    validateCustomDuration,
} from '../../resources/js/Components/Meetings/LiveKit/meetingRecordingState.js';

const recording = (overrides = {}) => ({
    reference: 'rec-1',
    status: 'recording',
    started_at: '2026-10-03T09:00:00Z',
    scheduled_stop_at: '2026-10-03T09:12:00Z',
    server_now_at: '2026-10-03T09:05:00Z',
    duration_seconds: null,
    ...overrides,
});

test('the remaining time is derived from the stored deadline and the server clock', () => {
    const clock = {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 1000};

    assert.equal(recordingRemainingSeconds(recording(), clock, 1000), 420);
    // One second later the countdown has moved down by exactly one second, which is
    // the whole point: it tracks the deadline rather than accumulating locally.
    assert.equal(recordingRemainingSeconds(recording(), clock, 2000), 419);
    // Seven minutes later the stored deadline has been reached.
    assert.equal(recordingRemainingSeconds(recording(), clock, 1000 + 420000), 0);
});

test('a refresh resumes the countdown instead of restarting it', () => {
    const first = recordingRemainingSeconds(recording(), {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, 0);
    // A reload two minutes later re-anchors on the same stored deadline.
    const afterRefresh = recordingRemainingSeconds(recording(), {serverNowAt: '2026-10-03T09:07:00Z', receivedAt: 0}, 0);

    assert.equal(first, 420);
    assert.equal(afterRefresh, 300);
    assert.equal(first - afterRefresh, 120);
});

test('two participants with wrong local clocks agree on the remaining time', () => {
    const accurate = recordingRemainingSeconds(recording(), {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 500}, 500);
    // The same server truth, delivered with a completely different local anchor.
    const skewed = recordingRemainingSeconds(recording(), {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 900000}, 900000);
    const skewedLater = recordingRemainingSeconds(recording(), {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 900000}, 900000 + 60000);

    assert.equal(accurate, 420);
    assert.equal(skewed, 420);
    // One local minute later both agree on 360, because both derive it from the
    // stored deadline rather than from when their own page happened to load.
    assert.equal(skewedLater, 360);
    assert.equal(recordingRemainingSeconds(recording(), {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 500}, 500 + 60000), 360);
});

test('an open-ended recording has no countdown and falls back to counting up', () => {
    const open = recording({scheduled_stop_at: null});

    assert.equal(recordingRemainingSeconds(open, {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, 0), null);
    assert.equal(recordingBadgeSeconds(open, {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, 0), 300);
});

test('an expired deadline reads 00:00 and never goes negative', () => {
    const past = recording({scheduled_stop_at: '2026-10-03T09:01:00Z'});

    assert.equal(recordingRemainingSeconds(past, {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, 0), 0);
    assert.equal(recordingRemainingSeconds(past, {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, 600000), 0);
    assert.equal(recordingRemainingSeconds(past, {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, -600000), 0);
});

test('a missing or malformed deadline degrades safely rather than showing NaN', () => {
    assert.equal(recordingRemainingSeconds(null, null, 0), null);
    assert.equal(recordingRemainingSeconds(recording({scheduled_stop_at: 'nonsense'}), {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, 0), null);
    assert.equal(recordingRemainingSeconds(recording(), {receivedAt: NaN}, 0), null);
    assert.equal(recordingElapsedSeconds(recording({started_at: 'nonsense'}), {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 0}, 0), null);
});

test('a monotonic read taken before the response anchor still cannot go negative', () => {
    const clock = {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 5000};

    assert.equal(recordingRemainingSeconds(recording({scheduled_stop_at: '2026-10-03T09:10:00Z'}), clock, 5000), 300);
    // A read earlier than the anchor is treated as no elapsed time, so it can never
    // push the deadline further away than the server ever promised.
    assert.equal(recordingRemainingSeconds(recording({scheduled_stop_at: '2026-10-03T09:10:00Z'}), clock, 0), 300);
    assert.equal(recordingRemainingSeconds(recording({scheduled_stop_at: '2026-10-03T09:10:00Z'}), clock, -60000), 300);
});

test('the badge formats minutes and hours the way a meeting timer reads', () => {
    assert.equal(formatRecordingClock(0), '00:00');
    assert.equal(formatRecordingClock(9), '00:09');
    assert.equal(formatRecordingClock(125), '02:05');
    assert.equal(formatRecordingClock(702), '11:42');
    assert.equal(formatRecordingClock(3600), '01:00:00');
    assert.equal(formatRecordingClock(4215), '01:10:15');
    assert.equal(formatRecordingClock(NaN), '00:00');
    assert.equal(formatRecordingClock(-5), '00:00');
});

test('live and settling states are distinguished from terminal ones', () => {
    assert.equal(recordingLive(recording({status: 'starting'})), true);
    assert.equal(recordingLive(recording({status: 'recording'})), true);
    assert.equal(recordingLive(recording({status: 'stopping'})), false);
    assert.equal(recordingSettling(recording({status: 'stopping'})), true);
    assert.equal(recordingSettling(recording({status: 'processing'})), true);
    assert.equal(recordingSettling(recording({status: 'ready'})), false);
    assert.equal(recordingLive(recording({status: 'failed'})), false);
    assert.equal(recordingLive(null), false);
});

test('a student never sees the start control', () => {
    // can_start_recording comes from the server, and a student never has it.
    assert.deepEqual(startRecordingControlState({meeting: {can_start_recording: false}, recording: null}), {kind: 'hidden'});
    assert.deepEqual(startRecordingControlState({meeting: {}, recording: null}), {kind: 'hidden'});
    assert.deepEqual(startRecordingControlState({meeting: null, recording: null}), {kind: 'hidden'});
});

test('an authorised host sees Start recording when nothing is running', () => {
    assert.deepEqual(
        startRecordingControlState({meeting: {can_start_recording: true}, recording: null}),
        {kind: 'start'},
    );
});

test('an authorised host sees Stop recording while a capture is live', () => {
    const live = recording();
    assert.deepEqual(
        startRecordingControlState({meeting: {can_start_recording: true}, recording: live}),
        {kind: 'stop', recording: live},
    );
});

test('a start already in flight shows Starting rather than Start again', () => {
    assert.deepEqual(
        startRecordingControlState({meeting: {can_start_recording: true}, recording: recording({status: 'starting'}), requesting: true}),
        {kind: 'starting'},
    );
});

test('a settling recording shows Stopping and offers no start', () => {
    assert.deepEqual(
        startRecordingControlState({meeting: {can_start_recording: true}, recording: recording({status: 'processing'})}),
        {kind: 'stopping'},
    );
});

test('a custom duration must be a whole number inside the bounds', () => {
    const bounds = {min: 1, max: 240};

    assert.deepEqual(validateCustomDuration('12', bounds), {valid: true, minutes: 12});
    assert.deepEqual(validateCustomDuration(' 30 ', bounds), {valid: true, minutes: 30});
    assert.equal(validateCustomDuration('', bounds).valid, false);
    assert.equal(validateCustomDuration('0', bounds).key, 'meetingRoom.recording.customDurationTooShort');
    assert.equal(validateCustomDuration('241', bounds).key, 'meetingRoom.recording.customDurationTooLong');
    assert.equal(validateCustomDuration('12.5', bounds).key, 'meetingRoom.recording.customDurationWhole');
    assert.equal(validateCustomDuration('-5', bounds).key, 'meetingRoom.recording.customDurationWhole');
    assert.equal(validateCustomDuration('twelve', bounds).key, 'meetingRoom.recording.customDurationWhole');
    assert.equal(validateCustomDuration('12e3', bounds).key, 'meetingRoom.recording.customDurationWhole');
    assert.equal(validateCustomDuration(undefined, bounds).key, 'meetingRoom.recording.customDurationRequired');
});

test('the recording card states are translated in both locales', () => {
    const states = ['starting', 'recording', 'stopping', 'processing', 'ready', 'failed'];

    for (const locale of [en, km]) {
        for (const state of states) {
            const value = locale.meetingRoom.recording.cardStates[state];
            assert.equal(typeof value, 'string', `${state} missing`);
            assert.ok(value.length > 0, `${state} empty`);
        }
    }

    // Khmer must be a real translation rather than the English string copied over.
    assert.notEqual(km.meetingRoom.recording.cardStates.ready, en.meetingRoom.recording.cardStates.ready);
});

test('every recording control string the room and the card render exists in both locales', () => {
    const keys = [
        'start', 'startRecording', 'stop', 'stopRecording', 'stopping', 'recording',
        'cardTitle', 'notice', 'settlingNotice', 'startTitle', 'startHint',
        'durationLegend', 'durationManual', 'durationMinutes', 'durationCustom',
        'customDurationLabel', 'customDurationRequired', 'customDurationWhole',
        'customDurationTooShort', 'customDurationTooLong', 'durationBounds',
        'cancel', 'confirmStart', 'stopTitle', 'stopHint', 'confirmStop',
        'watch', 'processing', 'failed', 'cardDuration', 'cardRecordedBy', 'cardRecordedAt',
    ];

    for (const key of keys) {
        assert.equal(typeof en.meetingRoom.recording[key], 'string', `en.${key} missing`);
        assert.ok(en.meetingRoom.recording[key].length > 0, `en.${key} empty`);
        assert.equal(typeof km.meetingRoom.recording[key], 'string', `km.${key} missing`);
        assert.ok(km.meetingRoom.recording[key].length > 0, `km.${key} empty`);
    }
});

test('the duration presets the dialog offers are the ones the brief specifies', () => {
    const source = ['5 minutes', '10 minutes', '12 minutes', '30 minutes'];

    for (const label of source) {
        assert.equal(en.meetingRoom.recording.durationMinutes.replace('{minutes}', label.split(' ')[0]), label);
    }

    assert.equal(typeof en.meetingRoom.recording.durationManual, 'string');
    assert.equal(typeof en.meetingRoom.recording.durationCustom, 'string');
});

test('the recording card never receives a stored file reference', () => {
    const projection = recording({status: 'ready'});

    for (const forbidden of ['storage_disk', 'storage_path', 'provider_output_path', 'provider_egress_id']) {
        assert.equal(forbidden in projection, false, `${forbidden} leaked into the projection`);
    }
});

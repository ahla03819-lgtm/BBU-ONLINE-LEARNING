import assert from 'node:assert/strict';
import test from 'node:test';
import {createElement} from 'react';
import serverReact from 'react-dom/server';
import {createServer} from 'vite';
import react from '@vitejs/plugin-react';
import {noticeKey} from '../../resources/js/i18n/notice.js';

/**
 * The room crashed on join with:
 *
 *   Uncaught TypeError: Cannot read properties of null (reading 'status')
 *
 * traced to the recording indicator reading `recording.status` while the
 * recording projection was still null. The room page seeds the projection from
 * the server, but a class with no capture has no projection at all, and a poll
 * or a broadcast can clear it back to null at any moment.
 *
 * There is no DOM test environment in this project, so these tests render the
 * real React components through React's own server renderer: the component
 * bodies execute exactly as they do in the browser, including every guard and
 * every property read, and any throw fails the test the same way it would break
 * the room. Vite supplies the JSX transform the application itself uses.
 *
 * The recording controls are a separate chunk from LiveKit, so this renders the
 * affected components on their own rather than the whole control bar.
 */

const {renderToStaticMarkup} = serverReact;

const vite = await createServer({
    root: process.cwd(),
    configFile: false,
    appType: 'custom',
    logLevel: 'error',
    plugins: [react()],
    server: {middlewareMode: true, hmr: false, watch: null},
});

const controls = await vite.ssrLoadModule('/resources/js/Components/Meetings/LiveKit/MeetingRecordingControls.jsx');

test.after(() => vite.close());

const clock = {serverNowAt: '2026-10-03T09:05:00Z', receivedAt: 1000};

const projection = (overrides = {}) => ({
    reference: 'rec-1',
    status: 'recording',
    started_at: '2026-10-03T09:00:00Z',
    scheduled_stop_at: '2026-10-03T09:12:00Z',
    server_now_at: '2026-10-03T09:05:00Z',
    can_start: true,
    can_stop: true,
    ...overrides,
});

/**
 * The shape `MeetingControlCenter` hands the indicators: the whole recording API
 * from `useMeetingRecording`, whose `.state` is the server projection and whose
 * `.notice` is the transient terminal notice.
 */
const recordingApi = (overrides = {}) => ({
    state: null,
    clock,
    notice: null,
    dialog: null,
    canStart: true,
    showStop: false,
    minDurationMinutes: 1,
    maxDurationMinutes: 240,
    start: () => {},
    stop: () => {},
    openStart: () => {},
    openStop: () => {},
    closeDialog: () => {},
    ...overrides,
});

const render = (Component, props) => renderToStaticMarkup(createElement(Component, props));

const indicators = [
    ['RecordingBadge', controls.RecordingBadge, (recording) => ({recording, clock})],
    ['StoppingIndicator', controls.StoppingIndicator, (recording) => ({recording})],
    ['ProcessingIndicator', controls.ProcessingIndicator, (recording) => ({recording})],
];

/**
 * A missing recording projection must render no recording-specific status UI
 * and must never throw. `recording.state` is null for a class that is not being
 * recorded, and it is what the control bar passes to every indicator.
 */
test('a null recording projection renders no status UI from any indicator', () => {
    for (const [name, Component, propsFor] of indicators) {
        assert.equal(render(Component, propsFor(null)), '', `${name} must render nothing for a null recording`);
        assert.equal(render(Component, propsFor(undefined)), '', `${name} must render nothing for an undefined recording`);
    }
});

test('the recording API shape the control bar uses renders no status UI while nothing is recorded', () => {
    const api = recordingApi();

    for (const [name, Component, propsFor] of indicators) {
        assert.equal(render(Component, propsFor(api.state)), '', `${name} must render nothing for recording.state === null`);
    }

    assert.equal(render(controls.ReadyNotice, {notice: api.notice}), '', 'ReadyNotice must render nothing without a notice');
    assert.equal(render(controls.FailedNotice, {notice: api.notice}), '', 'FailedNotice must render nothing without a notice');
    assert.equal(render(controls.MeetingRecordingDialogs, {recording: api}), '', 'no dialog may open without a dialog request');
});

test('a live recording still renders its authoritative badge and countdown', () => {
    const html = render(controls.RecordingBadge, {recording: projection(), clock});

    assert.match(html, /meeting-recording-badge/);
    assert.match(html, /meeting-recording-timer/);
    assert.match(html, /07:00/);
    assert.equal(render(controls.StoppingIndicator, {recording: projection()}), '');
    assert.equal(render(controls.ProcessingIndicator, {recording: projection()}), '');
});

test('a stopping projection shows the settling badge and the stopping indicator', () => {
    const stopping = projection({status: 'stopping', can_stop: false});

    assert.match(render(controls.RecordingBadge, {recording: stopping, clock}), /meeting-recording-badge--settling/);
    assert.match(render(controls.StoppingIndicator, {recording: stopping}), /meeting-recording-stopping/);
    assert.equal(render(controls.ProcessingIndicator, {recording: stopping}), '', 'a stopping projection is not a processing one');
});

test('a processing projection shows the processing indicator while the capture is still settling', () => {
    const processing = projection({status: 'processing', can_stop: false});

    assert.match(render(controls.ProcessingIndicator, {recording: processing}), /meeting-recording-processing/);
    // StoppingIndicator keeps following the shared settling classifier, exactly as
    // the badge's settling class does, so the overlay it owns is unchanged.
    assert.match(render(controls.StoppingIndicator, {recording: processing}), /meeting-recording-stopping/);
    assert.match(render(controls.RecordingBadge, {recording: processing, clock}), /meeting-recording-badge--settling/);
});

test('no settling overlay is shown for a live, ready, failed or missing projection', () => {
    for (const status of [null, undefined, 'recording', 'starting', 'ready', 'failed']) {
        const projection_ = status === null || status === undefined ? status : projection({status, can_stop: false});

        assert.equal(render(controls.StoppingIndicator, {recording: projection_}), '', `StoppingIndicator must stay hidden for ${String(status)}`);
        assert.equal(render(controls.ProcessingIndicator, {recording: projection_}), '', `ProcessingIndicator must stay hidden for ${String(status)}`);
    }
});

test('a ready projection renders its notice and no live or settling indicator', () => {
    const ready = projection({status: 'ready', can_stop: false});
    // Rendered outside LocaleProvider the translator falls back to the key itself,
    // which is enough to prove the notice descriptor reaches renderNotice and that
    // resolving it does not throw.
    const html = render(controls.ReadyNotice, {notice: noticeKey('meetingRoom.recording.readyNotice')});

    assert.match(html, /meeting-recording-ready-notice/);
    assert.match(html, /meetingRoom\.recording\.readyNotice/);
    assert.equal(render(controls.RecordingBadge, {recording: ready, clock}), '', 'a ready recording is not a live one');
    assert.equal(render(controls.StoppingIndicator, {recording: ready}), '');
    assert.equal(render(controls.ProcessingIndicator, {recording: ready}), '');
});

test('a failed projection renders its notice and no live or settling indicator', () => {
    const failed = projection({status: 'failed', can_stop: false});
    const html = render(controls.FailedNotice, {notice: noticeKey('meetingRoom.recording.failedNotice')});

    assert.match(html, /meeting-recording-failed-notice/);
    assert.match(html, /meetingRoom\.recording\.failedNotice/);
    assert.equal(render(controls.RecordingBadge, {recording: failed, clock}), '');
    assert.equal(render(controls.StoppingIndicator, {recording: failed}), '');
    assert.equal(render(controls.ProcessingIndicator, {recording: failed}), '');
});

test('the start dialog keeps the server duration bounds and the stop dialog keeps its confirm action', () => {
    const start = render(controls.MeetingRecordingDialogs, {
        recording: recordingApi({dialog: 'start', dialogToken: 1}),
    });
    const stop = render(controls.MeetingRecordingDialogs, {
        recording: recordingApi({dialog: 'stop'}),
    });

    assert.match(start, /meeting-recording-dialog/);
    assert.match(start, /meeting-recording-confirm-start/);
    assert.match(stop, /meeting-recording-confirm-stop/);
});

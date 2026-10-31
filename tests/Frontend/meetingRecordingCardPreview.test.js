import assert from 'node:assert/strict';
import test from 'node:test';
import {createElement} from 'react';
import serverReact from 'react-dom/server';
import {createServer} from 'vite';
import react from '@vitejs/plugin-react';
import {readFileSync} from 'node:fs';

const {renderToStaticMarkup} = serverReact;

const vite = await createServer({
    root: process.cwd(),
    configFile: false,
    appType: 'custom',
    logLevel: 'error',
    plugins: [react()],
    server: {middlewareMode: true, hmr: false, watch: null},
});

const {default: MeetingRecordingCard, activateRecordingPlayer} = await vite.ssrLoadModule('/resources/js/Components/Collaboration/Messaging/MeetingRecordingCard.jsx');
const source = readFileSync('resources/js/Components/Collaboration/Messaging/MeetingRecordingCard.jsx', 'utf8');

test.after(() => vite.close());

const recording = (overrides = {}) => ({
    status: 'ready',
    playback_url: '/classes/1/meetings/meeting-1/recordings/recording-1/play',
    meeting_title: 'Algebra',
    duration_seconds: 42,
    ...overrides,
});

test('a ready recording renders a source-less player and accessible Watch control', () => {
    const html = renderToStaticMarkup(createElement(MeetingRecordingCard, {recording: recording()}));

    assert.match(html, /data-testid="meeting-recording-preview"/);
    assert.match(html, /data-testid="meeting-recording-player"/);
    assert.doesNotMatch(html, /src="\/classes\/1\/meetings\/meeting-1\/recordings\/recording-1\/play"/);
    assert.doesNotMatch(html, /<source\b/);
    assert.match(html, /preload="none"/);
    assert.match(html, /aria-label="meetingRoom\.recording\.watch"/);
    assert.match(html, /data-testid="meeting-recording-play"/);
    assert.equal((html.match(/<video/g) ?? []).length, 1, 'the card must retain exactly one source-less player for the user gesture');
    assert.doesNotMatch(html, /meeting-recording-watch-fallback/);
    assert.doesNotMatch(html, /autoPlay|autoplay/, 'recordings must not autoplay');
});

test('only the selected Watch gesture assigns a route and begins playback', async () => {
    const selected = {playCalls: 0, play() { this.playCalls += 1; return Promise.resolve(); }};
    const unopened = {playCalls: 0, play() { this.playCalls += 1; return Promise.resolve(); }};

    const result = activateRecordingPlayer(selected, recording().playback_url);
    await result;

    assert.equal(selected.src, recording().playback_url);
    assert.equal(selected.muted, false);
    assert.equal(selected.playCalls, 1);
    assert.equal(unopened.src, undefined);
    assert.equal(unopened.playCalls, 0);
    assert.equal(activateRecordingPlayer(unopened, null), null);
    assert.equal(unopened.playCalls, 0);
});

test('the card has no eager metadata, seek, load, or playback behavior', () => {
    assert.doesNotMatch(source, /onLoadedMetadata/);
    assert.doesNotMatch(source, /currentTime/);
    assert.doesNotMatch(source, /\.load\(/);
    assert.match(source, /onClick=\{playInline\}/);
    assert.match(source, /controls=\{activated\}/);
    assert.match(source, /playsInline/);
    assert.match(source, /onError=\{\(\) => setMediaFailed\(true\)\}/);
    assert.match(source, /data-testid="meeting-recording-watch-fallback"/);
});

test('a recording without an authorized ready URL does not request media and retains its status fallback', () => {
    const html = renderToStaticMarkup(createElement(MeetingRecordingCard, {
        recording: recording({status: 'processing', playback_url: null}),
    }));

    assert.doesNotMatch(html, /meeting-recording-thumbnail/);
    assert.doesNotMatch(html, /meeting-recording-player/);
    assert.doesNotMatch(html, /meeting-recording-play/);
    assert.match(html, /meetingRoom\.recording\.processing/);
});

test('failed, unavailable, and additional ready cards do not preload media', () => {
    const failed = renderToStaticMarkup(createElement(MeetingRecordingCard, {recording: recording({status: 'failed'})}));
    const unavailable = renderToStaticMarkup(createElement(MeetingRecordingCard, {recording: recording({playback_url: null})}));
    const readyCards = renderToStaticMarkup(createElement('div', null,
        createElement(MeetingRecordingCard, {recording: recording()}),
        createElement(MeetingRecordingCard, {recording: recording({meeting_title: 'Biology'})}),
    ));

    assert.doesNotMatch(failed, /meeting-recording-player/);
    assert.doesNotMatch(unavailable, /meeting-recording-player/);
    assert.doesNotMatch(readyCards, /src="\/classes\/1\/meetings\/meeting-1\/recordings\/recording-1\/play"/);
    assert.doesNotMatch(renderToStaticMarkup(createElement(MeetingRecordingCard, {recording: recording()})), /src="\/classes\/1\/meetings\/meeting-1\/recordings\/recording-1\/play"/, 'an unopened re-render must remain source-less');
});

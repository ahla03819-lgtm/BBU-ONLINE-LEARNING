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

const {default: MeetingRecordingCard} = await vite.ssrLoadModule('/resources/js/Components/Collaboration/Messaging/MeetingRecordingCard.jsx');
const source = readFileSync('resources/js/Components/Collaboration/Messaging/MeetingRecordingCard.jsx', 'utf8');

test.after(() => vite.close());

const recording = (overrides = {}) => ({
    status: 'ready',
    playback_url: '/classes/1/meetings/meeting-1/recordings/recording-1/play',
    meeting_title: 'Algebra',
    duration_seconds: 42,
    ...overrides,
});

const message = {id: 9, type: 'meeting_recording'};

test('a moderator sees a three-dot action menu button on the recording card', () => {
    const html = renderToStaticMarkup(createElement(MeetingRecordingCard, {recording: recording(), message, canModerate: true, onModerate: () => {}}));
    assert.match(html, /aria-label="collaboration\.actions"/, 'the menu button must be present for a moderator');
    assert.match(source, /collaboration\.removeChat/, 'the Remove-from-chat menu item must exist in the source');
    assert.doesNotMatch(html, /delete recording/i, 'no fake delete-recording action may be offered');
});

test('an unauthorized viewer sees no menu on the recording card', () => {
    const html = renderToStaticMarkup(createElement(MeetingRecordingCard, {recording: recording(), message, canModerate: false, onModerate: () => {}}));
    assert.doesNotMatch(html, /aria-label="collaboration\.actions"/, 'no menu button for unauthorized viewers');
});

test('the Remove-from-chat action requires confirmation and only fires for a moderator', () => {
    assert.match(source, /window\.confirm\(t\('collaboration\.removeChatConfirm'\)\)/, 'confirmation must be required');
    assert.match(source, /onModerate\(message, reason\.trim\(\)\)/, 'the existing moderate handler must be invoked with a reason');
    assert.doesNotMatch(source, /deleteRecording/, 'no delete-recording lifecycle may be implemented');
});

test('the recording card retains a source-less native inline player and preview', () => {
    const html = renderToStaticMarkup(createElement(MeetingRecordingCard, {recording: recording(), message, canModerate: true, onModerate: () => {}}));
    assert.match(html, /data-testid="meeting-recording-preview"/);
    assert.match(html, /data-testid="meeting-recording-player"/);
    assert.doesNotMatch(html, /src="\/classes\/1\/meetings\/meeting-1\/recordings\/recording-1\/play"/);
    assert.match(html, /playsInline=""/);
    assert.match(html, /data-testid="meeting-recording-play"/);
});

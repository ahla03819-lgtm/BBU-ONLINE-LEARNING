import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const projectRoot = process.cwd();

function readProjectFile(relativePath) {
    return readFileSync(resolve(projectRoot, relativePath), 'utf8');
}

const meetingAiApi = readProjectFile('resources/js/Components/Meetings/AI/meetingAiApi.js');
const useMeetingAiData = readProjectFile('resources/js/Components/Meetings/AI/useMeetingAiData.js');
const BBUBuddyPanel = readProjectFile('resources/js/Components/Meetings/AI/BBUBuddyPanel.jsx');

test('meetingAiApi exposes transcript read helper with GET method', () => {
    assert.ok(meetingAiApi.includes('getTranscript'), 'meetingAiApi should export getTranscript');
    assert.ok(meetingAiApi.includes("method = 'GET'") || meetingAiApi.includes('method: \'GET\''), 'getTranscript should use GET');
});

test('meetingAiApi exposes notes read helper with GET method', () => {
    assert.ok(meetingAiApi.includes('getNotes'), 'meetingAiApi should export getNotes');
    assert.ok(meetingAiApi.includes('ai/notes'), 'getNotes should target the notes endpoint');
});

test('meetingAiApi exposes summary read helper with GET method', () => {
    assert.ok(meetingAiApi.includes('getSummary'), 'meetingAiApi should export getSummary');
    assert.ok(meetingAiApi.includes('ai/summary'), 'getSummary should target the summary endpoint');
});

test('meetingAiApi exposes notes generation with POST method only', () => {
    assert.ok(meetingAiApi.includes('requestNotes'), 'meetingAiApi should export requestNotes');
    assert.ok(meetingAiApi.includes("api('POST'"), 'requestNotes should use POST via api helper');
});

test('meetingAiApi exposes summary generation with POST method only', () => {
    assert.ok(meetingAiApi.includes('requestSummary'), 'meetingAiApi should export requestSummary');
    assert.ok(meetingAiApi.includes("api('POST'"), 'requestSummary should use POST via api helper');
});

test('meetingAiApi does not expose a POST transcript helper', () => {
    assert.ok(!meetingAiApi.includes('postTranscript') && !meetingAiApi.includes('requestTranscript'), 'no POST transcript helper should exist');
    assert.ok(!meetingAiApi.includes("ai/transcript', {method: 'POST'"), 'transcript endpoint should not be called with POST');
});

test('meetingAiApi routes are scoped by schoolClass and meeting UUID', () => {
    assert.ok(meetingAiApi.includes('schoolClass.id') && meetingAiApi.includes('meeting.uuid'), 'routes should be scoped by class and meeting');
});

test('useMeetingAiData does not reference media lifecycle functions', () => {
    assert.ok(!useMeetingAiData.includes('setCameraEnabled'), 'useMeetingAiData must not reference setCameraEnabled');
    assert.ok(!useMeetingAiData.includes('setMicrophoneEnabled'), 'useMeetingAiData must not reference setMicrophoneEnabled');
    assert.ok(!useMeetingAiData.includes('setScreenShareEnabled'), 'useMeetingAiData must not reference setScreenShareEnabled');
    assert.ok(!useMeetingAiData.includes('meetingMediaIntent'), 'useMeetingAiData must not reference meetingMediaIntent');
});

test('useMeetingAiData does not implement continuous rapid transcript polling', () => {
    assert.ok(!useMeetingAiData.includes('loadTranscript') || !useMeetingAiData.includes('setInterval(loadTranscript'), 'transcript loading must not be on a rapid interval');
});

test('useMeetingAiData provides bounded status refresh for notes and summary', () => {
    assert.ok(useMeetingAiData.includes('3000') || useMeetingAiData.includes('startNotesPolling'), 'notes polling should be bounded');
    assert.ok(useMeetingAiData.includes('stopNotesPolling'), 'notes polling should be cancellable');
    assert.ok(useMeetingAiData.includes('stopSummaryPolling'), 'summary polling should be cancellable');
});

test('useMeetingAiData maps generating status to loading state', () => {
    assert.ok(useMeetingAiData.includes("status === 'generating'"), 'hook should recognise generating status');
    assert.ok(useMeetingAiData.includes("loading: true"), 'hook should set loading during generation');
});

test('useMeetingAiData maps ready and failed statuses safely', () => {
    assert.ok(useMeetingAiData.includes("'ready'") && useMeetingAiData.includes("'failed'"), 'hook should recognise ready and failed statuses');
    assert.ok(useMeetingAiData.includes('stopNotesPolling') && useMeetingAiData.includes('stopSummaryPolling'), 'hook should stop polling on terminal statuses');
});

test('meetingAiApi normalizes CSRF and JSON headers like existing meeting hooks', () => {
    assert.ok(meetingAiApi.includes('X-CSRF-TOKEN'), 'API should send CSRF token');
    assert.ok(meetingAiApi.includes('Accept: \'application/json\''), 'API should accept JSON');
});

test('meetingAiApi handles error responses without exposing raw server errors', () => {
    assert.ok(meetingAiApi.includes('payload.message') || meetingAiApi.includes('payload.errors'), 'API should extract safe error messages');
});

test('BBUBuddyPanel accepts AI data props without breaking existing contract', () => {
    assert.ok(BBUBuddyPanel.includes('canGenerateNotes'), 'BBUBuddyPanel should accept canGenerateNotes');
    assert.ok(BBUBuddyPanel.includes('canGenerateSummary'), 'BBUBuddyPanel should accept canGenerateSummary');
    assert.ok(BBUBuddyPanel.includes('notes'), 'BBUBuddyPanel should accept notes');
    assert.ok(BBUBuddyPanel.includes('summary'), 'BBUBuddyPanel should accept summary');
    assert.ok(BBUBuddyPanel.includes('onGenerateNotes'), 'BBUBuddyPanel should accept onGenerateNotes');
    assert.ok(BBUBuddyPanel.includes('onGenerateSummary'), 'BBUBuddyPanel should accept onGenerateSummary');
});

test('BBUBuddyPanel does not expose listening transcribing or translating manual controls', () => {
    const actionLines = BBUBuddyPanel.split('\n').filter((line) => line.includes('listening') || line.includes('transcribing') || line.includes('translating'));
    const hasManualControls = actionLines.some((line) => line.includes('onClick') || line.includes('move('));
    assert.ok(!hasManualControls, 'BBUBuddyPanel should not expose manual controls for fake STT states');
});

test('BBUBuddyPanel renders notes and summary only when authorized', () => {
    assert.ok(BBUBuddyPanel.includes('canGenerateNotes'), 'notes section should be gated by canGenerateNotes');
    assert.ok(BBUBuddyPanel.includes('canGenerateSummary'), 'summary section should be gated by canGenerateSummary');
});

test('BBUBuddyPanel shows safe unavailable states for failed notes and summary', () => {
    assert.ok(BBUBuddyPanel.includes('notesUnavailable'), 'BBUBuddyPanel should show notes unavailable state');
    assert.ok(BBUBuddyPanel.includes('summaryUnavailable'), 'BBUBuddyPanel should show summary unavailable state');
});

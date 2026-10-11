import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';

import {
    recordingLive,
    recordingSettling,
} from '../../resources/js/Components/Meetings/LiveKit/meetingRecordingState.js';

// Resolved from this file rather than from the working directory, so the test
// behaves the same from the repository root and from a linked worktree.
const source = readFileSync(
    new URL('../../resources/js/Components/Meetings/LiveKit/MeetingRecordingControls.jsx', import.meta.url),
    'utf8',
);

/**
 * These guards exist because the room crashed on the very first paint with:
 *
 *   Uncaught TypeError: Cannot read properties of null (reading 'status')
 *
 * The crash happened when `MeetingControlCenter` rendered
 * `<ProcessingIndicator recording={recording.state}/>` while the recording
 * projection was still null. The room page seeds the projection from the
 * server, but it can legitimately be null (no active capture), and a poll or a
 * broadcast can clear it back to null at any moment. `ProcessingIndicator`
 * used to read `recording.status` directly, so a null projection threw.
 *
 * The fix is at the boundary that owns the read: the indicator itself guards
 * the read, so every caller is safe without repeating the check.
 */

test('ProcessingIndicator is null-safe when the recording projection is null', () => {
    const guard = source.match(/function ProcessingIndicator\(\{recording\}\) \{[\s\S]*?if \(!recording \|\| recording\.status !== 'processing'\) return null;[\s\S]*?\}/);

    assert.ok(guard, 'ProcessingIndicator must guard a null recording before reading .status');
    assert.ok(
        guard[0].includes("!recording || recording.status !== 'processing'"),
        'the guard must short-circuit on a null recording before the status read',
    );
});

test('ProcessingIndicator is null-safe when the recording projection is undefined', () => {
    // The same guard covers undefined because `!undefined` is true.
    const guard = source.match(/function ProcessingIndicator\(\{recording\}\) \{[\s\S]*?if \(!recording \|\| recording\.status !== 'processing'\) return null;[\s\S]*?\}/);

    assert.ok(guard, 'ProcessingIndicator must guard an undefined recording before reading .status');
});

test('StoppingIndicator is null-safe when the recording projection is null', () => {
    // StoppingIndicator delegates to recordingSettling, which already guards null.
    assert.equal(recordingSettling(null), false);
    assert.equal(recordingSettling(undefined), false);
});

test('the recording state helpers never throw on a null projection', () => {
    assert.equal(recordingLive(null), false);
    assert.equal(recordingLive(undefined), false);
    assert.equal(recordingSettling(null), false);
    assert.equal(recordingSettling(undefined), false);
});

test('a null recording projection renders no indicator of any kind', () => {
    // The contract: with a null projection, neither indicator may render.
    // StoppingIndicator returns null through recordingSettling(null) === false,
    // and ProcessingIndicator returns null through its explicit guard.
    assert.equal(recordingSettling(null), false);
    const guard = source.match(/function ProcessingIndicator\(\{recording\}\) \{[\s\S]*?if \(!recording \|\| recording\.status !== 'processing'\) return null;[\s\S]*?\}/);

    assert.ok(guard, 'ProcessingIndicator must return null for a null projection');
});

test('a historical ready recording renders no live or settling indicator', () => {
    // A terminal projection must not trigger the settling indicators.
    assert.equal(recordingSettling({status: 'ready'}), false);
    assert.equal(recordingSettling({status: 'failed'}), false);
    assert.equal(recordingLive({status: 'ready'}), false);
    assert.equal(recordingLive({status: 'failed'}), false);
});

test('an active recording projection is still recognised as live', () => {
    assert.equal(recordingLive({status: 'starting'}), true);
    assert.equal(recordingLive({status: 'recording'}), true);
});

test('a stopping or processing projection is still recognised as settling', () => {
    assert.equal(recordingSettling({status: 'stopping'}), true);
    assert.equal(recordingSettling({status: 'processing'}), true);
});

test('the module still exports every recording indicator the control center renders', () => {
    // Regression: renaming or dropping an export would silently break the room.
    const exports = source.match(/export \{([^}]+)\}/);

    assert.ok(exports, 'MeetingRecordingControls must re-export its indicators');
    const names = exports[1].split(',').map((name) => name.trim());

    for (const required of ['RecordingBadge', 'MeetingRecordingDialogs', 'StoppingIndicator', 'ProcessingIndicator', 'ReadyNotice', 'FailedNotice']) {
        assert.ok(names.includes(required), `${required} must be exported`);
    }
});
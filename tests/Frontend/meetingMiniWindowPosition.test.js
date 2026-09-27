import assert from 'node:assert/strict';
import test from 'node:test';
import {
    clampMiniWindowPosition,
    dragMiniWindowPosition,
    shouldStartMiniWindowDrag,
} from '../../resources/js/Components/Meetings/LiveKit/meetingMiniWindowPosition.js';

test('drag starts only for primary left-button gestures on the drag region', () => {
    assert.equal(shouldStartMiniWindowDrag({button: 0, isPrimary: true, targetIsInteractive: false}), true);
    assert.equal(shouldStartMiniWindowDrag({button: 0, isPrimary: true, targetIsInteractive: true}), false);
    assert.equal(shouldStartMiniWindowDrag({button: 2, isPrimary: true, targetIsInteractive: false}), false);
    assert.equal(shouldStartMiniWindowDrag({button: 0, isPrimary: false, targetIsInteractive: false}), false);
});

test('drag follows pointer movement and clamps against every viewport edge', () => {
    const size = {width: 220, height: 140};
    const viewport = {width: 800, height: 600};
    const moved = dragMiniWindowPosition({
        origin: {left: 100, top: 80},
        pointerStart: {x: 10, y: 10},
        pointer: {x: 40, y: 35},
        size,
        viewport,
    });

    assert.deepEqual(moved, {left: 130, top: 105});
    assert.deepEqual(clampMiniWindowPosition({left: -40, top: -20}, size, viewport), {left: 0, top: 0});
    assert.deepEqual(clampMiniWindowPosition({left: 900, top: 700}, size, viewport), {left: 580, top: 460});
});

test('a mini window larger than the viewport is clamped to the top-left origin', () => {
    assert.deepEqual(
        clampMiniWindowPosition({left: 100, top: 80}, {width: 500, height: 700}, {width: 320, height: 500}),
        {left: 0, top: 0},
    );
});
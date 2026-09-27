import assert from 'node:assert/strict';
import test from 'node:test';
import {
    clearMeetingMediaIntent,
    meetingMediaIntentKey,
    readMeetingMediaIntent,
    screenShareIntentChange,
    writeMeetingMediaIntent,
} from '../../resources/js/Components/Meetings/LiveKit/meetingMediaIntent.js';

function memoryStorage() {
    const values = new Map();
    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
    };
}

test('media intent is scoped by meeting and authenticated user and stores only booleans', () => {
    const originalStorage = globalThis.sessionStorage;
    const storage = memoryStorage();
    globalThis.sessionStorage = storage;

    try {
        const firstKey = meetingMediaIntentKey('meeting-a', 12);
        const secondKey = meetingMediaIntentKey('meeting-b', 12);
        const otherUserKey = meetingMediaIntentKey('meeting-a', 13);

        assert.notEqual(firstKey, secondKey);
        assert.notEqual(firstKey, otherUserKey);
        assert.equal(writeMeetingMediaIntent(firstKey, {microphoneEnabled: true, cameraEnabled: true}), true);
        assert.equal(writeMeetingMediaIntent(firstKey, {microphoneEnabled: false}), true);
        assert.deepEqual(readMeetingMediaIntent(firstKey), {
            microphoneEnabled: false,
            cameraEnabled: true,
            wasScreenSharing: false,
        });
        assert.equal(writeMeetingMediaIntent(firstKey, {microphoneEnabled: true}), true);
        assert.equal(writeMeetingMediaIntent(firstKey, {wasScreenSharing: true}), true);
        assert.deepEqual(readMeetingMediaIntent(firstKey), {
            microphoneEnabled: true,
            cameraEnabled: true,
            wasScreenSharing: true,
        });
        assert.equal(readMeetingMediaIntent(secondKey), null);
        assert.deepEqual(JSON.parse(storage.getItem(firstKey)), {
            version: 1,
            microphoneEnabled: true,
            cameraEnabled: true,
            wasScreenSharing: true,
        });
    } finally {
        if (originalStorage === undefined) delete globalThis.sessionStorage;
        else globalThis.sessionStorage = originalStorage;
    }
});

test('media intent can be cleared after leave or terminal meeting state', () => {
    const originalStorage = globalThis.sessionStorage;
    const storage = memoryStorage();
    globalThis.sessionStorage = storage;

    try {
        const key = meetingMediaIntentKey('meeting-a', 12);
        writeMeetingMediaIntent(key, {microphoneEnabled: true, cameraEnabled: false});
        clearMeetingMediaIntent(key);
        assert.equal(readMeetingMediaIntent(key), null);
    } finally {
        if (originalStorage === undefined) delete globalThis.sessionStorage;
        else globalThis.sessionStorage = originalStorage;
    }
});

test('screen share intent ignores initial off and teardown but tracks real starts and stops', () => {
    assert.equal(screenShareIntentChange({enabled: false, isUserInitiated: false, wasEnabled: false}), null);
    assert.equal(screenShareIntentChange({enabled: false, isUserInitiated: true, wasEnabled: false}), null);
    assert.equal(screenShareIntentChange({enabled: true, isUserInitiated: true, wasEnabled: false}), true);
    assert.equal(screenShareIntentChange({enabled: false, isUserInitiated: false, wasEnabled: true}), false);
    assert.equal(screenShareIntentChange({enabled: false, isUserInitiated: true, wasEnabled: true}), false);
    assert.equal(screenShareIntentChange({enabled: false, isUserInitiated: false, wasEnabled: true, isUnmounting: true}), null);

    const originalStorage = globalThis.sessionStorage;
    const storage = memoryStorage();
    globalThis.sessionStorage = storage;

    try {
        const key = meetingMediaIntentKey('meeting-a', 12);
        writeMeetingMediaIntent(key, {wasScreenSharing: true});
        const nativeStop = screenShareIntentChange({enabled: false, isUserInitiated: false, wasEnabled: true});
        if (nativeStop !== null) writeMeetingMediaIntent(key, {wasScreenSharing: nativeStop});
        assert.equal(readMeetingMediaIntent(key)?.wasScreenSharing, false);

        writeMeetingMediaIntent(key, {wasScreenSharing: true});
        const pageTeardown = screenShareIntentChange({enabled: false, isUserInitiated: false, wasEnabled: true, isUnmounting: true});
        if (pageTeardown !== null) writeMeetingMediaIntent(key, {wasScreenSharing: pageTeardown});
        assert.equal(readMeetingMediaIntent(key)?.wasScreenSharing, true);
    } finally {
        if (originalStorage === undefined) delete globalThis.sessionStorage;
        else globalThis.sessionStorage = originalStorage;
    }
});
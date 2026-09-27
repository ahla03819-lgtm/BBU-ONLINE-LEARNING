import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import {
    clearConversationCallMediaIntent,
    conversationCallDurationSeconds,
    conversationCallMediaOutcome,
    conversationCallMediaPlan,
    conversationCallMediaIntentKey,
    readConversationCallMediaIntent,
    writeConversationCallMediaIntent,
} from '../../resources/js/Components/Conversations/conversationCallMediaIntent.js';

function memoryStorage() {
    const values = new Map();
    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
    };
}

test('audio and audio-only calls never request camera acquisition', () => {
    assert.deepEqual(conversationCallMediaPlan({callType: 'audio'}), {microphoneEnabled: true, cameraEnabled: false, acquireCamera: false});
    assert.deepEqual(conversationCallMediaPlan({callType: 'video', audioOnly: true}), {microphoneEnabled: true, cameraEnabled: false, acquireCamera: false});
    assert.deepEqual(conversationCallMediaPlan({callType: 'video', mediaIntent: {cameraEnabled: false, microphoneEnabled: true}}), {microphoneEnabled: true, cameraEnabled: false, acquireCamera: false});
    assert.equal(conversationCallMediaPlan({callType: 'video'}).acquireCamera, true);
});

test('camera denial leaves audio usable while microphone denial has a retry message', () => {
    assert.deepEqual(conversationCallMediaOutcome({microphoneStatus: 'fulfilled', cameraStatus: 'rejected'}), {
        microphoneUnavailable: false,
        cameraUnavailable: true,
        message: 'Camera unavailable. The audio call continues; allow camera access and select Camera On to retry.',
    });
    assert.match(conversationCallMediaOutcome({microphoneStatus: 'rejected'}).message, /select Unmute to retry/);
});

test('call media intent is scoped by call and user, contains only booleans, and clears on leave', () => {
    const originalStorage = globalThis.sessionStorage;
    const storage = memoryStorage();
    globalThis.sessionStorage = storage;

    try {
        const key = conversationCallMediaIntentKey('call-a', 12);
        assert.notEqual(key, conversationCallMediaIntentKey('call-b', 12));
        assert.notEqual(key, conversationCallMediaIntentKey('call-a', 13));
        assert.equal(writeConversationCallMediaIntent(key, {microphoneEnabled: true, cameraEnabled: false}), true);
        assert.deepEqual(readConversationCallMediaIntent(key), {microphoneEnabled: true, cameraEnabled: false});
        assert.deepEqual(Object.keys(JSON.parse(storage.getItem(key))).sort(), ['cameraEnabled', 'microphoneEnabled', 'version']);
        clearConversationCallMediaIntent(key);
        assert.equal(readConversationCallMediaIntent(key), null);
    } finally {
        if (originalStorage === undefined) delete globalThis.sessionStorage;
        else globalThis.sessionStorage = originalStorage;
    }
});

test('call duration uses server anchor across refresh and ignores client wall-clock skew', () => {
    const startedAt = '2026-09-27T10:00:00.000Z';
    const serverClock = {serverNowAt: '2026-09-27T10:02:00.000Z', receivedAt: 1000};
    assert.equal(conversationCallDurationSeconds(startedAt, 2000, serverClock), 121);
    assert.equal(conversationCallDurationSeconds(startedAt, 900, serverClock), 119);
    assert.equal(conversationCallDurationSeconds('invalid', 61000, serverClock), 0);
});

test('provider owns one LiveKitRoom across mode changes and restores calls with a fresh token', () => {
    const source = fs.readFileSync(path.join(process.cwd(), 'resources/js/Providers/PersistentConversationCallProvider.jsx'), 'utf8');
    const experience = fs.readFileSync(path.join(process.cwd(), 'resources/js/Components/Conversations/ConversationCallExperience.jsx'), 'utf8');
    const app = fs.readFileSync(path.join(process.cwd(), 'resources/js/app.jsx'), 'utf8');
    assert.match(source, /fetch\('\/conversation-calls\/active'/);
    assert.match(source, /connectCall\(call, \{mode: 'full', recovering: true\}\)/);
    assert.equal((experience.match(/<LiveKitRoom\b/g) || []).length, 1);
    assert.match(experience, /mode === 'mini'/);
    assert.match(experience, /<RoomAudioRenderer\/>/);
    assert.match(experience, /room\.disconnect\(\)/);
    assert.match(experience, /useConnectionState/);
    assert.match(experience, /Reconnecting/);
    assert.match(experience, /remoteTrack\?\.publication/);
    assert.match(experience, /localTrack/);
    assert.match(experience, /onDisconnected=\{\(\) => null\}/);
    assert.match(app, /<PersistentConversationCallProvider>/);
});
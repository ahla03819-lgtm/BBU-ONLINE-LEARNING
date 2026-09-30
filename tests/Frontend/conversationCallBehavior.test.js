import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {createTranslator} from '../../resources/js/i18n/translate.js';
import {noticeKey, renderNotice} from '../../resources/js/i18n/notice.js';
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
        messageKey: 'conversations.cameraBlocked',
    });
    assert.equal(conversationCallMediaOutcome({microphoneStatus: 'rejected'}).messageKey, 'conversations.micBlocked');
    // Nothing is blocked, so there is no notice to show.
    assert.equal(conversationCallMediaOutcome({microphoneStatus: 'fulfilled'}).messageKey, null);
});

test('the blocked-media notice keeps its English copy and is translated in Khmer', () => {
    const t = (locale) => createTranslator({messages: {en, km}, locale});
    const camera = noticeKey(conversationCallMediaOutcome({microphoneStatus: 'fulfilled', cameraStatus: 'rejected'}).messageKey);
    const microphone = noticeKey(conversationCallMediaOutcome({microphoneStatus: 'rejected'}).messageKey);

    assert.equal(
        renderNotice(camera, t('en')),
        'Camera unavailable. The audio call continues; allow camera access and select Camera On to retry.',
    );
    assert.equal(
        renderNotice(microphone, t('en')),
        'Microphone access is blocked. Allow microphone access and select Unmute to retry.',
    );
    assert.notEqual(renderNotice(camera, t('km')), renderNotice(camera, t('en')));
    assert.notEqual(renderNotice(microphone, t('km')), renderNotice(microphone, t('en')));
    // The same descriptor survives a locale switch without being re-created.
    assert.equal(renderNotice(camera, t('km')), renderNotice(camera, t('km')));
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

test('provider owns one LiveKitRoom across mode changes and restores calls with a fresh token', () => {    const source = fs.readFileSync(path.join(process.cwd(), 'resources/js/Providers/PersistentConversationCallProvider.jsx'), 'utf8');
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
// --- FullConversationCallOverlay scope fix -------------------------------------
//
// The overlay used to call setMediaMessage / setMicrophoneUnavailable /
// setCameraUnavailable, none of which existed in its scope, so clicking the
// microphone or camera control threw a ReferenceError at runtime.

const CALL_SOURCE = () => fs.readFileSync(path.join(process.cwd(), 'resources/js/Components/Conversations/ConversationCallExperience.jsx'), 'utf8');

/** Body of a single top-level function, so assertions stay scoped to it. */
const functionBody = (source, name) => {
    const start = source.indexOf(`function ${name}(`);

    assert.notEqual(start, -1, `${name} must exist`);

    const end = source.indexOf('\nfunction ', start + 1);

    return source.slice(start, end === -1 ? source.length : end);
};

const trackedSetters = ['setMediaMessage', 'setMicrophoneUnavailable', 'setCameraUnavailable'];

test('the full overlay declares no media setter, so its controls cannot throw', () => {
    const source = CALL_SOURCE();

    for (const component of ['MiniConversationCallWindow', 'FullConversationCallOverlay']) {
        const body = functionBody(source, component);

        for (const setter of trackedSetters) {
            assert.doesNotMatch(body, new RegExp(`\\b${setter}\\b`), `${component} must not reference an out-of-scope ${setter}`);
        }

        // It reports the failure upwards instead of mutating state it does not own.
        assert.match(body, /onTrackFailure/);
    }
});

test('both the mini window and the full overlay route a blocked device to the owner', () => {
    const source = CALL_SOURCE();

    for (const component of ['MiniConversationCallWindow', 'FullConversationCallOverlay']) {
        const body = functionBody(source, component);

        assert.match(body, /onFailure=\{\(\) => onTrackFailure\(Track\.Source\.Microphone\)\}/, `${component} microphone must report upwards`);
        assert.match(body, /onFailure=\{\(\) => onTrackFailure\(Track\.Source\.Camera\)\}/, `${component} camera must report upwards`);
        assert.match(body, /onTrackFailure/, `${component} must accept the callback`);
    }
});

test('the owner keeps a single copy of the media state and the failure transition', () => {
    const source = CALL_SOURCE();
    const body = functionBody(source, 'ActiveConversationCall');

    // No duplicate competing state: each value is declared exactly once, in the owner.
    for (const state of ['cameraUnavailable', 'microphoneUnavailable', 'mediaMessage']) {
        const declarations = source.match(new RegExp(`const \\[${state}, set[A-Z][A-Za-z]*\\] = useState`, 'g')) || [];

        assert.equal(declarations.length, 1, `${state} must have exactly one owner, found ${declarations.length}`);
        assert.ok(body.includes(declarations[0]), `${state} must be owned by ActiveConversationCall`);
    }

    assert.match(body, /const trackFailed = useCallback/);
    assert.match(body, /onTrackFailure: trackFailed/);
    assert.equal((source.match(/const \[mediaMessage, setMediaMessage\] = useState/g) || []).length, 1, 'the media notice descriptor must not be duplicated');
});

test('the failure transition still produces a locale-reactive notice', () => {
    const t = (locale) => createTranslator({messages: {en, km}, locale});

    for (const [key, expected] of [
        ['conversations.micBlocked', 'Microphone access is blocked. Allow microphone access and select Unmute to retry.'],
        ['conversations.cameraBlocked', 'Camera unavailable. The audio call continues; allow camera access and select Camera On to retry.'],
    ]) {
        const shown = [t('km'), t('en'), t('km'), t('en')].map((translate) => renderNotice(noticeKey(key), translate));

        assert.equal(shown[1], expected, `${key} English copy must be unchanged`);
        assert.match(shown[0], /[\u1780-\u17FF]/);
        assert.notEqual(shown[0], shown[1], `${key} must leave Khmer on a locale switch`);
        assert.equal(shown[0], shown[2], `${key} must return to Khmer`);
    }
});

test('media intent persistence and the leave path are unchanged by the scope fix', () => {
    const source = CALL_SOURCE();
    const body = functionBody(source, 'ActiveConversationCall');

    assert.match(body, /writeConversationCallMediaIntent\(mediaIntentKey/);
    assert.match(body, /localParticipant\.setMicrophoneEnabled\(false\)/);
    assert.match(body, /await onLeave\?\.\(\(\) => room\.disconnect\(\)\)/);
    assert.match(source, /mode === 'mini'/);
    assert.match(source, /<RoomAudioRenderer\/>/);
    assert.equal((source.match(/<LiveKitRoom\b/g) || []).length, 1);
});

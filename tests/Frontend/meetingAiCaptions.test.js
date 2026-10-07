import assert from 'node:assert/strict';
import test from 'node:test';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {
    CAPTION_MODES,
    normalizeCaptionMode,
    isCaptionMode,
    meetingAiPreferencesKey,
    readMeetingAiPreferences,
    writeMeetingAiPreferences,
    clearMeetingAiPreferences,
    defaultAiPreferences,
} from '../../resources/js/Components/Meetings/AI/useMeetingAiPreferences.js';
import {
    TRANSLATION_STATUSES,
    isTranslationStatus,
    resolveTranslationStatus,
    translationDisplayDescriptor,
} from '../../resources/js/Components/Meetings/AI/translationStatus.js';

const MEDIA_INTENT_PREFIX = 'bbu:meeting-media-intent:v1';
const AI_PREFERENCES_PREFIX = 'bbu:meeting-ai-preferences:v1';

function installFakeSessionStorage() {
    const store = {};
    const backup = globalThis.sessionStorage;
    globalThis.sessionStorage = {
        getItem: (key) => (Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null),
        setItem: (key, value) => { store[key] = String(value); },
        removeItem: (key) => { delete store[key]; },
        clear: () => { Object.keys(store).forEach((key) => delete store[key]); },
    };
    return () => {
        if (backup === undefined) {
            delete globalThis.sessionStorage;
        } else {
            globalThis.sessionStorage = backup;
        }
    };
}

test('caption modes are the four required modes in a stable order', () => {
    assert.deepEqual([...CAPTION_MODES], ['off', 'en', 'km', 'bilingual']);
});

test('normalizeCaptionMode accepts the four modes case-insensitively and trimmed', () => {
    assert.equal(normalizeCaptionMode('off'), 'off');
    assert.equal(normalizeCaptionMode('EN'), 'en');
    assert.equal(normalizeCaptionMode('  km  '), 'km');
    assert.equal(normalizeCaptionMode('Bilingual'), 'bilingual');
});

test('normalizeCaptionMode falls back to off for anything invalid', () => {
    assert.equal(normalizeCaptionMode('french'), 'off');
    assert.equal(normalizeCaptionMode(''), 'off');
    assert.equal(normalizeCaptionMode('english'), 'off');
    assert.equal(normalizeCaptionMode(null), 'off');
    assert.equal(normalizeCaptionMode(undefined), 'off');
    assert.equal(normalizeCaptionMode(42), 'off');
    assert.equal(normalizeCaptionMode({}), 'off');
});

test('isCaptionMode recognises only the four modes', () => {
    for (const mode of CAPTION_MODES) {
        assert.equal(isCaptionMode(mode), true, `${mode} should be a caption mode`);
    }
    assert.equal(isCaptionMode('off '), true);
    assert.equal(isCaptionMode('KM'), true);
    assert.equal(isCaptionMode('french'), false);
    assert.equal(isCaptionMode('english'), false);
    assert.equal(isCaptionMode(null), false);
    assert.equal(isCaptionMode(undefined), false);
});

test('meetingAiPreferencesKey is scoped to meeting and user', () => {
    const key = meetingAiPreferencesKey('mtg-uuid', 7);
    assert.equal(key, `${AI_PREFERENCES_PREFIX}:mtg-uuid:7`);
    assert.ok(key.startsWith(AI_PREFERENCES_PREFIX));
});

test('meetingAiPreferencesKey is separate from the meeting media intent key', () => {
    const prefsKey = meetingAiPreferencesKey('mtg-uuid', 7);
    const mediaKey = `${MEDIA_INTENT_PREFIX}:${encodeURIComponent('mtg-uuid')}:${encodeURIComponent('7')}`;
    assert.notEqual(prefsKey, mediaKey);
    assert.ok(!prefsKey.startsWith(MEDIA_INTENT_PREFIX), 'caption preferences must not share the media intent namespace');
    assert.ok(mediaKey.startsWith(MEDIA_INTENT_PREFIX));
});

test('meetingAiPreferencesKey returns null without complete context', () => {
    assert.equal(meetingAiPreferencesKey('', 7), null);
    assert.equal(meetingAiPreferencesKey('mtg-uuid'), null);
    assert.equal(meetingAiPreferencesKey('mtg-uuid', null), null);
    assert.equal(meetingAiPreferencesKey('mtg-uuid', undefined), null);
});

test('readMeetingAiPreferences returns the default when nothing is stored', () => {
    const restore = installFakeSessionStorage();
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        const prefs = readMeetingAiPreferences(key);
        assert.equal(prefs.captionMode, 'off');
        assert.equal(prefs.version, 1);
    } finally {
        restore();
    }
});

test('readMeetingAiPreferences returns the default without a storage backend', () => {
    const backup = globalThis.sessionStorage;
    delete globalThis.sessionStorage;
    try {
        const prefs = readMeetingAiPreferences(meetingAiPreferencesKey('mtg-uuid', 7));
        assert.equal(prefs.captionMode, 'off');
    } finally {
        if (backup !== undefined) globalThis.sessionStorage = backup;
    }
});

test('readMeetingAiPreferences persists a valid stored mode', () => {
    const restore = installFakeSessionStorage();
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        assert.equal(writeMeetingAiPreferences(key, {captionMode: 'bilingual'}), true);
        const prefs = readMeetingAiPreferences(key);
        assert.equal(prefs.captionMode, 'bilingual');
        assert.equal(prefs.version, 1);
    } finally {
        restore();
    }
});

test('an invalid stored caption mode falls back to off', () => {
    const restore = installFakeSessionStorage();
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        globalThis.sessionStorage.setItem(key, JSON.stringify({version: 1, captionMode: 'portuguese'}));
        const prefs = readMeetingAiPreferences(key);
        assert.equal(prefs.captionMode, 'off');
    } finally {
        restore();
    }
});

test('corrupted JSON falls back to the default preference', () => {
    const restore = installFakeSessionStorage();
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        globalThis.sessionStorage.setItem(key, '{ not valid json');
        const prefs = readMeetingAiPreferences(key);
        assert.equal(prefs.captionMode, 'off');
    } finally {
        restore();
    }
});

test('a version mismatch falls back to the default preference', () => {
    const restore = installFakeSessionStorage();
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        globalThis.sessionStorage.setItem(key, JSON.stringify({version: 99, captionMode: 'km'}));
        const prefs = readMeetingAiPreferences(key);
        assert.equal(prefs.captionMode, 'off');
    } finally {
        restore();
    }
});

test('writeMeetingAiPreferences normalises an invalid mode to off', () => {
    const restore = installFakeSessionStorage();
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        assert.equal(writeMeetingAiPreferences(key, {captionMode: 'garbage'}), true);
        const prefs = readMeetingAiPreferences(key);
        assert.equal(prefs.captionMode, 'off');
    } finally {
        restore();
    }
});

test('writeMeetingAiPreferences returns false without a storage backend', () => {
    const backup = globalThis.sessionStorage;
    delete globalThis.sessionStorage;
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        assert.equal(writeMeetingAiPreferences(key, {captionMode: 'en'}), false);
    } finally {
        if (backup !== undefined) globalThis.sessionStorage = backup;
    }
});

test('clearMeetingAiPreferences removes the stored record', () => {
    const restore = installFakeSessionStorage();
    try {
        const key = meetingAiPreferencesKey('mtg-uuid', 7);
        writeMeetingAiPreferences(key, {captionMode: 'en'});
        assert.ok(globalThis.sessionStorage.getItem(key));
        clearMeetingAiPreferences(key);
        assert.equal(globalThis.sessionStorage.getItem(key), null);
    } finally {
        restore();
    }
});

test('defaultAiPreferences returns a fresh canonical copy', () => {
    const prefs = defaultAiPreferences();
    assert.equal(prefs.captionMode, 'off');
    assert.equal(prefs.version, 1);
    assert.notEqual(prefs, defaultAiPreferences());
});

test('translation statuses match the documented contract', () => {
    assert.deepEqual([...TRANSLATION_STATUSES], ['idle', 'pending', 'ready', 'unavailable', 'failed']);
    for (const status of TRANSLATION_STATUSES) {
        assert.equal(isTranslationStatus(status), true);
    }
    assert.equal(isTranslationStatus('bogus'), false);
});

test('resolveTranslationStatus classifies inputs deterministically', () => {
    assert.equal(resolveTranslationStatus(null), 'idle');
    assert.equal(resolveTranslationStatus(undefined), 'idle');
    assert.equal(resolveTranslationStatus('pending'), 'pending');
    assert.equal(resolveTranslationStatus('failed'), 'failed');
    assert.equal(resolveTranslationStatus({status: 'ready', translatedText: 'សួស្តី'}), 'ready');
    assert.equal(resolveTranslationStatus({status: 'pending'}), 'pending');
    assert.equal(resolveTranslationStatus({status: 'unavailable'}), 'unavailable');
    assert.equal(resolveTranslationStatus({status: 'failed'}), 'failed');
    assert.equal(resolveTranslationStatus({}), 'idle');
});

test('a ready status with empty translated text is unavailable, not ready (no fake Khmer)', () => {
    assert.equal(resolveTranslationStatus({status: 'ready', translatedText: ''}), 'unavailable');
    assert.equal(resolveTranslationStatus({status: 'ready', translatedText: '   '}), 'unavailable');
    assert.equal(resolveTranslationStatus({status: 'ready', translatedText: null}), 'unavailable');
    assert.equal(resolveTranslationStatus({translatedText: ''}), 'idle');
    assert.equal(resolveTranslationStatus({translatedText: '   '}), 'idle');
});

test('translationDisplayDescriptor reflects the resolved status', () => {
    const ready = translationDisplayDescriptor({status: 'ready', translatedText: 'សួស្តី'});
    assert.equal(ready.status, 'ready');
    assert.equal(ready.isReady, true);

    const pending = translationDisplayDescriptor({status: 'pending'});
    assert.equal(pending.isPending, true);
    assert.equal(pending.isReady, false);
});

test('the caption mode labels exist in both locales', () => {
    for (const locale of [en, km]) {
        assert.equal(typeof locale.meetingRoom.ai.captions, 'string');
        assert.ok(locale.meetingRoom.ai.captions.length > 0);
        for (const key of ['off', 'english', 'khmer', 'bilingual']) {
            const label = locale.meetingRoom.ai[key];
            assert.equal(typeof label, 'string', `${key} should be a string label in the locale`);
            assert.ok(label.length > 0, `${key} label should not be empty`);
        }
    }
});

test('khmer caption labels are real translations, not english duplicates', () => {
    assert.notEqual(km.meetingRoom.ai.off, en.meetingRoom.ai.off);
    assert.notEqual(km.meetingRoom.ai.english, en.meetingRoom.ai.english);
    assert.notEqual(km.meetingRoom.ai.khmer, en.meetingRoom.ai.khmer);
    assert.notEqual(km.meetingRoom.ai.bilingual, en.meetingRoom.ai.bilingual);
    assert.notEqual(km.meetingRoom.ai.captions, en.meetingRoom.ai.captions);
});

test('the pending/unavailable translation labels exist in both locales', () => {
    for (const locale of [en, km]) {
        assert.equal(typeof locale.meetingRoom.ai.translationPending, 'string');
        assert.ok(locale.meetingRoom.ai.translationPending.length > 0);
        assert.equal(typeof locale.meetingRoom.ai.translationUnavailable, 'string');
        assert.ok(locale.meetingRoom.ai.translationUnavailable.length > 0);
    }
});

test('CaptionOverlay depends only on keys that already exist in both locales', () => {
    const suffix = {off: 'off', en: 'english', km: 'khmer', bilingual: 'bilingual'};
    for (const locale of [en, km]) {
        for (const mode of CAPTION_MODES) {
            const label = locale.meetingRoom.ai[suffix[mode]];
            assert.ok(typeof label === 'string' && label.length > 0, `missing caption mode label for ${mode}`);
        }
        assert.ok(typeof locale.meetingRoom.ai.captions === 'string' && locale.meetingRoom.ai.captions.length > 0);
    }
});

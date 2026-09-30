import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import path from 'node:path';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {createTranslator} from '../../resources/js/i18n/translate.js';
import {hasNotice, noticeKey, noticeRaw, renderNotice} from '../../resources/js/i18n/notice.js';
import {friendlyMediaError} from '../../resources/js/Hooks/Meetings/mediaPreviewError.js';
import {conversationCallMediaOutcome} from '../../resources/js/Components/Conversations/conversationCallMediaIntent.js';

const translatorFor = (locale) => createTranslator({messages: {en, km}, locale});
const read = (file) => fs.readFileSync(path.join(process.cwd(), file), 'utf8');

const LOBBY = 'resources/js/Pages/Meetings/Lobby.jsx';
const PREVIEW = 'resources/js/Hooks/Meetings/useMediaPreview.js';
const CALL = 'resources/js/Components/Conversations/ConversationCallExperience.jsx';

/** A descriptor stored once, then rendered repeatedly as the user switches. */
const visibleAcross = (notice, locales = ['km', 'en', 'km', 'en']) => locales.map((locale) => renderNotice(notice, translatorFor(locale)));

test('a meeting lobby error descriptor survives km -> en -> km', () => {
    for (const key of [
        'meetingRoom.errors.removed',
        'meetingRoom.errors.alreadyInAnother',
        'meetingRoom.errors.joinFailed',
        'meetingRoom.errors.providerUnavailable',
        'meetingRoom.errors.requestFailed',
        'meetingRoom.errors.cancelRequestFailed',
        'meetingRoom.errors.updateRequestFailed',
    ]) {
        const shown = visibleAcross(noticeKey(key));

        assert.match(shown[0], /[\u1780-\u17FF]/, `${key} must render Khmer first`);
        assert.equal(shown[1], shown[3], `${key} must render identically in English both times`);
        assert.notEqual(shown[0], shown[1], `${key} must not stay Khmer after switching to English`);
        assert.equal(shown[0], shown[2], `${key} must return to Khmer`);
    }
});

test('the lobby no longer stores a translated string in error state', () => {
    const source = read(LOBBY);
    const forbidden = [
        [/setError\(t\(/, 'setError must never receive a translated string'],
        [/setError\(problem\.message\s*\|\|/, 'a raw exception message must go through noticeRaw'],
        [/setError\(problem\.message\)/, 'a bare raw exception message must go through noticeRaw'],
        [/setError\(data\.message\s*\|\|/, 'raw server text must go through noticeRaw'],
        [/throw new Error\([^)]*t\(/, 'a translated fallback must not be frozen into a thrown Error'],
    ];

    for (const [pattern, reason] of forbidden) {
        assert.doesNotMatch(source, pattern, reason);
    }

    assert.match(source, /noticeRaw\(problem\.message\)/);
    assert.match(source, /noticeRaw\(data\.message\)/);
    assert.match(source, /renderNotice\(media\.error, t\)/);
    assert.match(source, /renderNotice\(error, t\)/);
});

test('a media preview error survives locale switching', () => {
    const failures = [
        {name: 'NotAllowedError', device: 'meetingRoom.lobby.mediaError.cameraOrMicrophone'},
        {name: 'SecurityError', device: 'meetingRoom.controlCenter.cameraLabel'},
        {name: 'NotFoundError', device: 'meetingRoom.controlCenter.cameraLabel'},
        {name: 'NotReadableError', device: 'meetingRoom.controlCenter.microphoneLabel'},
        {name: 'OverconstrainedError', device: 'meetingRoom.controlCenter.cameraLabel'},
        {name: 'UnknownError', device: 'meetingRoom.lobby.mediaError.cameraOrMicrophone'},
    ];

    for (const {name, device} of failures) {
        const notice = friendlyMediaError({name}, device);
        const shown = visibleAcross(notice);

        assert.ok(hasNotice(notice), `${name} must produce a notice`);
        assert.match(shown[0], /[\u1780-\u17FF]/, `${name} must render Khmer first`);
        assert.notEqual(shown[0], shown[1], `${name} must not stay Khmer after switching to English`);
        assert.equal(shown[0], shown[2], `${name} must return to Khmer`);
    }
});

test('the media preview device label inside an error is not frozen at the time of failure', () => {
    // The pre-translated-label bug: the device name was resolved when the error
    // fired, so only the sentence switched while the device name stayed behind.
    const notice = friendlyMediaError({name: 'NotAllowedError'}, 'meetingRoom.controlCenter.cameraLabel');
    const english = renderNotice(notice, translatorFor('en'));
    const khmer = renderNotice(notice, translatorFor('km'));

    assert.equal(english, 'Camera permission was blocked. Allow access in your browser settings, then try again.');
    assert.match(khmer, /កាមេរា/);
    assert.notEqual(english, khmer);
});

test('the media preview no longer stores a translated string in error state', () => {
    const source = read(PREVIEW);

    assert.doesNotMatch(source, /setError\(t\(/, 'setError must never receive a translated string');
    assert.match(source, /noticeKey\('meetingRoom\.lobby\.deviceUnavailable'\)/);
    assert.match(source, /noticeKey\('meetingRoom\.lobby\.micTestUnavailable'\)/);
    assert.match(source, /noticeKey\('meetingRoom\.lobby\.speakerTestFailed'\)/);
    // A device label key is passed, not an already translated label.
    assert.doesNotMatch(source, /friendlyMediaError\(problem, t\(/);
    assert.match(source, /from '\.\/mediaPreviewError'/);
});

test('a conversation call media message survives locale switching', () => {
    const blocked = [
        conversationCallMediaOutcome({microphoneStatus: 'rejected'}),
        conversationCallMediaOutcome({microphoneStatus: 'fulfilled', cameraStatus: 'rejected'}),
    ];

    for (const outcome of blocked) {
        const shown = visibleAcross(noticeKey(outcome.messageKey));

        assert.match(shown[0], /[\u1780-\u17FF]/, `${outcome.messageKey} must render Khmer first`);
        assert.notEqual(shown[0], shown[1], `${outcome.messageKey} must not stay Khmer after switching to English`);
        assert.equal(shown[0], shown[2], `${outcome.messageKey} must return to Khmer`);
    }

    // Nothing blocked means nothing to show.
    assert.equal(conversationCallMediaOutcome({microphoneStatus: 'fulfilled'}).messageKey, null);
    assert.equal(hasNotice(noticeKey(conversationCallMediaOutcome({microphoneStatus: 'fulfilled'}).messageKey)), false);
});

test('the conversation call no longer stores a translated string in mediaMessage', () => {
    const source = read(CALL);

    assert.doesNotMatch(source, /setMediaMessage\(t\(/, 'setMediaMessage must never receive a translated string');
    assert.doesNotMatch(source, /setMediaMessage\('[^']*'\)/, 'no hardcoded English sentence may be stored');
    assert.match(source, /noticeKey\(outcome\.messageKey\)/);
    assert.equal((source.match(/renderNotice\(mediaMessage, t\)/g) || []).length, 2, 'both call windows must resolve at render time');
});

test('raw server messages remain unchanged across locale switching', () => {
    const serverMessages = [
        'You are already connected to this meeting.',
        'The meeting is not active.',
        'You do not have permission to perform this action.',
    ];

    for (const message of serverMessages) {
        const shown = visibleAcross(noticeRaw(message));

        assert.deepEqual(shown, [message, message, message, message], 'a raw server message must never be translated or altered');
    }
});

test('a fallback translated message still switches correctly', () => {
    // The `raw message OR translated fallback` shape used across the lobby: the
    // raw branch wins when the server said something, the key branch otherwise.
    const withServerText = (message) => noticeRaw(message) || noticeKey('meetingRoom.errors.requestFailed');
    const withoutServerText = (message) => noticeRaw(message) || noticeKey('meetingRoom.errors.requestFailed');

    assert.equal(visibleAcross(withServerText('Room is closed.')).every((text) => text === 'Room is closed.'), true);
    assert.equal(renderNotice(withServerText(null), translatorFor('en')), 'Unable to request entry to this meeting.');

    const fallback = visibleAcross(withoutServerText(undefined));

    assert.match(fallback[0], /[\u1780-\u17FF]/);
    assert.equal(fallback[1], 'Unable to request entry to this meeting.');
    assert.equal(fallback[0], fallback[2]);
});

test('an empty raw message falls through to the translated fallback', () => {
    // The token request throws `new Error(data.message || '')` and relies on the
    // catch to supply the catalogued fallback.
    const build = (message) => noticeRaw(message) || noticeKey('meetingRoom.errors.joinFailed');

    assert.equal(renderNotice(build(''), translatorFor('en')), 'Unable to join this meeting.');
    assert.equal(renderNotice(build(undefined), translatorFor('en')), 'Unable to join this meeting.');
    assert.equal(renderNotice(build('Validation failed.'), translatorFor('en')), 'Validation failed.');
    assert.notEqual(renderNotice(build(''), translatorFor('km')), 'Unable to join this meeting.');
});

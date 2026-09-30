import assert from 'node:assert/strict';
import test from 'node:test';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {createTranslator} from '../../resources/js/i18n/translate.js';
import {
    hasNotice,
    isNotice,
    notice,
    noticeKey,
    noticeRaw,
    renderFirstNotice,
    renderNotice,
} from '../../resources/js/i18n/notice.js';

const messages = {en, km};
const translatorFor = (locale) => createTranslator({messages, locale});

const KHMER_SCRIPT = /[\u1780-\u17FF]/;

test('a notice descriptor resolves per locale instead of freezing one string', () => {
    // Regression: the screen-share notice used to be translated at the moment it
    // was stored, so it stayed Khmer after switching back to English.
    const notice = noticeKey('meetingRoom.controlCenter.shareUnavailable');

    assert.deepEqual(notice, {key: 'meetingRoom.controlCenter.shareUnavailable', params: {}});
    assert.match(renderNotice(notice, translatorFor('km')), KHMER_SCRIPT);
    assert.equal(
        renderNotice(notice, translatorFor('en')),
        'Screen sharing was cancelled or is unavailable in this browser.',
    );
});

test('a visible notice follows a locale switch in both directions', () => {
    const notice = noticeKey('meetingRoom.controlCenter.shareUnavailable');
    const shown = [];

    // The notice is created once and stays mounted while the user switches
    // language; each render must produce the text for the locale of that moment.
    for (const locale of ['km', 'en', 'km', 'en']) {
        shown.push(renderNotice(notice, translatorFor(locale)));
    }

    assert.match(shown[0], KHMER_SCRIPT);
    assert.equal(shown[1], shown[3]);
    assert.notEqual(shown[0], shown[1]);
    assert.match(shown[2], KHMER_SCRIPT);
    assert.equal(shown[0], shown[2]);
});

test('every transient meeting notice key resolves differently per locale', () => {
    const keys = [
        'meetingRoom.controlCenter.shareUnavailable',
        'meetingRoom.controlCenter.cameraEnableFailed',
        'meetingRoom.controlCenter.micEnableFailed',
        'meetingRoom.controlCenter.deviceUpdated',
        'meetingRoom.controlCenter.deviceSwitchFailed',
        'meetingRoom.controlCenter.moderationFailed',
        'meetingRoom.errors.roomJoinFailed',
        'meetingRoom.errors.interrupted',
        'meetingRoom.errors.linkCopied',
        'meetingRoom.errors.linkCopyFailed',
        'meetingRoom.errors.copyUnavailable',
        'meetingRoom.errors.chatSendFailed',
        'meetingRoom.errors.updateRequestFailed',
        'meetingRoom.stage.restoreFailed',
        'meetingRoom.waitingRoom.admitted',
        'meetingRoom.waitingRoom.denied',
        'meetingRoom.waitingRoom.updateFailed',
        'meetingRoom.waitingRoom.moderationFailed',
        'meetingRoom.panels.endFailed',
        'meetingRoom.panels.removeFailed',
        'meetingRoom.panels.participantRemoved',
        'meetingRoom.panels.joinRequestRejected',
        'meetingRoom.panels.endRequestSent',
    ];

    for (const key of keys) {
        const english = renderNotice(noticeKey(key), translatorFor('en'));
        const khmer = renderNotice(noticeKey(key), translatorFor('km'));

        assert.notEqual(english, humanised(key), `${key} is missing from the English catalogue`);
        assert.notEqual(khmer, humanised(key), `${key} is missing from the Khmer catalogue`);
        assert.notEqual(english, khmer, `${key} is not translated in Khmer`);
    }
});

// A missing key degrades to the last dotted segment, capitalised.
const humanised = (key) => {
    const segment = key.split('.').pop();

    return segment.charAt(0).toUpperCase() + segment.slice(1);
};

test('placeholder params are interpolated and follow the locale', () => {
    const params = {device: 'Camera', count: 3};
    const notice = noticeKey('meetingRoom.controlCenter.deviceUpdated', params);

    assert.equal(renderNotice(notice, translatorFor('en')), 'Camera device updated.');
    assert.notEqual(renderNotice(notice, translatorFor('km')), 'Camera device updated.');
    assert.deepEqual(params, {device: 'Camera', count: 3}, 'params must not be mutated');
});

test('lazy params keep nested translations locale-reactive', () => {
    // The media-restore notice joins translated device names with a translated
    // separator, so the whole fragment has to be rebuilt on every render.
    const notice = noticeKey('meetingRoom.stage.restoreFailed', {
        devices: (t) => [t('meetingRoom.controlCenter.camera'), t('meetingRoom.controlCenter.mic')]
            .join(t('meetingRoom.stage.deviceSeparator')),
    });

    const english = renderNotice(notice, translatorFor('en'));
    const khmer = renderNotice(notice, translatorFor('km'));

    assert.match(english, /^Camera and Mic could not be restored\./);
    assert.match(khmer, /មិនអាចស្ដារ/);
    assert.notEqual(english, khmer);
});

test('raw server messages stay supported and are never looked up as keys', () => {
    const notice = noticeRaw('You are already connected to this meeting.');

    assert.deepEqual(notice, {raw: 'You are already connected to this meeting.'});
    // Identical in both locales: there is no key to translate.
    assert.equal(renderNotice(notice, translatorFor('en')), 'You are already connected to this meeting.');
    assert.equal(renderNotice(notice, translatorFor('km')), 'You are already connected to this meeting.');
});

test('an absent notice renders as an empty string and is not shown', () => {
    for (const value of [null, undefined, '', 0, false, []]) {
        assert.equal(renderNotice(value, translatorFor('en')), '');
        assert.equal(hasNotice(value), false);
    }

    assert.equal(hasNotice(noticeKey('meetingRoom.errors.linkCopied')), true);
    assert.equal(hasNotice(noticeRaw('boom')), true);
    assert.equal(isNotice({key: 'x'}), true);
    assert.equal(isNotice('x'), true);
    assert.equal(isNotice(42), false);
});

test('noticeKey and noticeRaw normalise empty input so callers can clear safely', () => {
    assert.equal(noticeKey(''), null);
    assert.equal(noticeKey(null), null);
    assert.equal(noticeKey(undefined), null);
    assert.equal(noticeRaw(''), null);
    assert.equal(noticeRaw(null), null);
    assert.equal(noticeRaw(undefined), null);
    assert.equal(renderNotice(noticeKey(''), translatorFor('en')), '');
});

test('a pre-existing descriptor passes through notice() unchanged', () => {
    const descriptor = noticeKey('meetingRoom.errors.interrupted');

    assert.equal(notice(descriptor), descriptor);
    assert.deepEqual(notice('server text'), {raw: 'server text'});
    assert.equal(notice(null), null);
});

test('renderFirstNotice prefers the earliest notice that resolves', () => {
    const media = noticeKey('meetingRoom.errors.linkCopied');
    const moderation = noticeKey('meetingRoom.panels.endRequestSent');
    const t = translatorFor('en');

    assert.equal(renderFirstNotice([null, moderation], t), 'End meeting request sent.');
    assert.equal(renderFirstNotice([media, moderation], t), 'Meeting link copied.');
    assert.equal(renderFirstNotice([null, null], t), '');
    assert.equal(renderFirstNotice([], t), '');
    assert.equal(renderFirstNotice(undefined, t), '');
});

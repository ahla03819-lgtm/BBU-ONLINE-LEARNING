import assert from 'node:assert/strict';
import test from 'node:test';
import en from '../../resources/js/i18n/en.js';
import km from '../../resources/js/i18n/km.js';
import {createTranslator, FALLBACK_LOCALE, humanizeKey, normalizeLocale, resolveKey} from '../../resources/js/i18n/translate.js';
import {nextLocaleOnChoice, shouldPersistLocale, syncLocaleFromServer} from '../../resources/js/i18n/localeSelection.js';

const messages = {en, km};
const translatorFor = (locale) => createTranslator({messages, locale});

test('English is the default locale and the fallback for unsupported values', () => {
    assert.equal(FALLBACK_LOCALE, 'en');
    assert.equal(normalizeLocale(undefined), 'en');
    assert.equal(normalizeLocale('fr'), 'en');
    assert.equal(normalizeLocale('km'), 'km');
    assert.equal(normalizeLocale('en'), 'en');
});

test('English renders the required shared shell strings', () => {
    const t = translatorFor('en');

    assert.equal(t('nav.items.dashboard'), 'Dashboard');
    assert.equal(t('nav.items.classes'), 'Classes');
    assert.equal(t('nav.items.attendance'), 'Attendance');
    assert.equal(t('nav.items.results'), 'Results');
    assert.equal(t('nav.items.coursework'), 'Coursework');
    assert.equal(t('nav.items.calendar'), 'Calendar');
    assert.equal(t('nav.items.meetings'), 'Meetings');
    assert.equal(t('nav.items.collaboration'), 'Collaboration');
    assert.equal(t('nav.items.chats'), 'Chats');
    assert.equal(t('nav.items.notifications'), 'Notifications');
    assert.equal(t('topbar.searchPlaceholder'), 'Search BBU ONLINE LEARNING...');
    assert.equal(t('topbar.signOut'), 'Sign out');
    assert.equal(t('language.label'), 'Language');
    assert.equal(t('common.save'), 'Save');
    assert.equal(t('common.cancel'), 'Cancel');
    assert.equal(t('common.close'), 'Close');
    assert.equal(t('common.edit'), 'Edit');
    assert.equal(t('common.delete'), 'Delete');
    assert.equal(t('common.search'), 'Search');
    assert.equal(t('common.back'), 'Back');
    assert.equal(t('common.loading'), 'Loading…');
    assert.equal(t('common.retry'), 'Retry');
    assert.equal(t('common.confirm'), 'Confirm');
});

test('switching to Khmer renders Khmer for the required shared shell strings', () => {
    const t = translatorFor('km');

    assert.equal(t('nav.items.dashboard'), 'ផ្ទាំងគ្រប់គ្រង');
    assert.equal(t('nav.items.classes'), 'ថ្នាក់រៀន');
    assert.equal(t('nav.items.attendance'), 'វត្តមាន');
    assert.equal(t('nav.items.results'), 'លទ្ធផល');
    assert.equal(t('nav.items.coursework'), 'កិច្ចការសិក្សា');
    assert.equal(t('nav.items.calendar'), 'ប្រតិទិន');
    assert.equal(t('nav.items.meetings'), 'កិច្ចប្រជុំ');
    assert.equal(t('nav.items.collaboration'), 'ការសហការ');
    assert.equal(t('nav.items.chats'), 'ការជជែក');
    assert.equal(t('nav.items.notifications'), 'ការជូនដំណឹង');
    assert.equal(t('topbar.signOut'), 'ចាកចេញ');
    assert.equal(t('language.label'), 'ភាសា');
    assert.equal(t('meetings.show.joinMeeting'), 'ចូលរួមកិច្ចប្រជុំ');
    assert.equal(t('meetings.show.startMeeting'), 'ចាប់ផ្តើមកិច្ចប្រជុំ');
    assert.equal(t('meetings.show.endMeeting'), 'បញ្ចប់កិច្ចប្រជុំ');
    assert.equal(t('meetings.show.cancelMeeting'), 'បោះបង់កិច្ចប្រជុំ');
    assert.equal(t('meetings.show.actionsTitle'), 'សកម្មភាពដែលអាចប្រើបាន');
});

test('switching back to English restores the English interface', () => {
    const km2en = translatorFor('km');
    assert.equal(km2en('nav.items.chats'), 'ការជជែក');

    const en = translatorFor('en');
    assert.equal(en('nav.items.chats'), 'Chats');
    assert.equal(en('meetings.show.joinMeeting'), 'Join meeting');
});

test('the language selector labels are the endonyms in both locales', () => {
    const english = translatorFor('en');
    const khmer = translatorFor('km');

    assert.equal(english('language.english'), 'English');
    assert.equal(english('language.khmer'), 'ខ្មែរ');
    assert.equal(khmer('language.english'), 'English');
    assert.equal(khmer('language.khmer'), 'ខ្មែរ');
});

test('a key missing from Khmer falls back to English instead of rendering nothing', () => {
    const catalogue = {en: {nav: {dashboard: 'Dashboard'}}, km: {}};
    const missing = [];
    const t = createTranslator({messages: catalogue, locale: 'km', onMissingKey: (key, locale, fellBack) => missing.push([key, locale, fellBack])});

    assert.equal(t('nav.dashboard'), 'Dashboard');
    assert.deepEqual(missing, [['nav.dashboard', 'km', true]]);
});

test('a key missing from every catalogue still renders a readable label', () => {
    const missing = [];
    const t = createTranslator({messages: {en: {}, km: {}}, locale: 'km', onMissingKey: (key) => missing.push(key)});

    assert.equal(t('nav.dashboard'), 'Dashboard');
    assert.equal(t('meetings.emptyState'), 'Empty State');
    assert.equal(t('a.b.someKeyName'), 'Some Key Name');
    assert.deepEqual(missing, ['nav.dashboard', 'meetings.emptyState', 'a.b.someKeyName']);
});

test('placeholders and plurals resolve for the active locale', () => {
    const en = translatorFor('en');
    const km = translatorFor('km');

    assert.equal(en('meetingRoom.stage.screenOf', {name: 'Dara'}), 'Dara’s screen');
    assert.equal(km('meetingRoom.stage.screenOf', {name: 'ដារា'}), 'អេក៚ររបស់ ដារា');
    assert.equal(en('notifications.unreadUpdate', {count: 1}), '1 unread update');
    assert.equal(en('notifications.unreadUpdate', {count: 4}), '4 unread updates');
    assert.equal(km('notifications.unreadUpdate', {count: 4}), '4 ការជូនដំណឹងដែលមិនទាន់អាន');
});

test('an unknown placeholder is left intact rather than rendering undefined', () => {
    const t = translatorFor('km');

    assert.equal(t('language.current', {}), 'ភាសាបច្ចុប្បន្ន៖ {language}');
    assert.ok(! t('nav.items.chats').includes('undefined'));
    assert.ok(! t('meetings.show.joinMeeting').includes('null'));
});

test('the Khmer catalogue never introduces keys English does not define', () => {
    const walk = (source, prefix = '') => Object.entries(source).flatMap(([key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;

        return value && typeof value === 'object' ? walk(value, path) : [path];
    });
    const extra = walk(km).filter((path) => resolveKey(en, path) === undefined);

    assert.deepEqual(extra, []);
});

test('the meeting, chat and account strings required by the brief are translated', () => {
    const t = translatorFor('km');
    const required = [
        'meetings.liveClasses', 'meetings.show.detailsTitle', 'meetings.show.classContextTitle',
        'meetings.status.scheduled', 'meetings.status.active', 'meetings.status.starting',
        'meetings.status.ending', 'meetings.status.ended', 'meetings.status.cancelled',
        'meetingRoom.lobby.readyToJoin', 'meetingRoom.lobby.waitingRoom', 'meetingRoom.lobby.joinMeeting',
        'meetingRoom.lobby.enterLiveClass', 'meetingRoom.lobby.requestToJoin', 'meetingRoom.lobby.cameraOn',
        'meetingRoom.lobby.cameraOff', 'meetingRoom.lobby.micOn', 'meetingRoom.lobby.micOff',
        'meetingRoom.lobby.testMic', 'meetingRoom.lobby.speakerLabel', 'meetingRoom.lobby.testSpeaker',
        'meetingRoom.controlCenter.chat', 'meetingRoom.controlCenter.people', 'meetingRoom.controlCenter.raise',
        'meetingRoom.controlCenter.lower', 'meetingRoom.controlCenter.react', 'meetingRoom.controlCenter.camera',
        'meetingRoom.controlCenter.muted', 'meetingRoom.controlCenter.share', 'meetingRoom.controlCenter.stopShare',
        'meetingRoom.controlCenter.view', 'meetingRoom.controlCenter.host', 'meetingRoom.controlCenter.more',
        'meetingRoom.controlCenter.leave', 'meetingRoom.panels.chatTitle', 'meetingRoom.panels.people',
        'conversations.title', 'conversations.newMessage', 'conversations.send', 'conversations.calling',
        'conversations.incomingCall', 'conversations.accept', 'conversations.decline',
        'conversations.audioOnly', 'conversations.mute', 'conversations.unmute', 'conversations.endCall',
        'account.appearance.languageHeading', 'topbar.signOut', 'language.label',
    ];
    const untranslated = required.filter((key) => t(key) === enValue(key));

    assert.deepEqual(untranslated, []);
});

function enValue(key) {
    let current = en;

    for (const segment of key.split('.')) {
        current = current?.[segment];
    }

    return current;
}

test('a user choice stays selected while the server has not caught up', () => {
    const chosen = nextLocaleOnChoice('km');
    const pending = syncLocaleFromServer({locale: chosen, chosen, serverLocale: 'en'});

    assert.equal(pending.locale, 'km');
    assert.equal(pending.chosen, 'km');
    assert.equal(pending.confirmed, false);
});

test('the server preference becomes authoritative once it matches the choice', () => {
    const confirmed = syncLocaleFromServer({locale: 'km', chosen: 'km', serverLocale: 'km'});

    assert.equal(confirmed.locale, 'km');
    assert.equal(confirmed.chosen, null);
    assert.equal(confirmed.confirmed, true);
});

test('an untouched account follows the server preference on every navigation', () => {
    const first = syncLocaleFromServer({locale: 'en', chosen: null, serverLocale: 'km'});
    const second = syncLocaleFromServer({locale: first.locale, chosen: first.chosen, serverLocale: 'km'});

    assert.equal(first.locale, 'km');
    assert.equal(first.chosen, null);
    assert.equal(second.locale, 'km');
});

test('only an unconfirmed user choice is written back to the server', () => {
    assert.equal(shouldPersistLocale({locale: 'km', chosen: 'km'}), true);
    assert.equal(shouldPersistLocale({locale: 'km', chosen: null}), false);
    assert.equal(shouldPersistLocale({locale: 'en', chosen: 'km'}), false);
});

test('choosing a language normalises unsupported input to English', () => {
    assert.equal(nextLocaleOnChoice('km'), 'km');
    assert.equal(nextLocaleOnChoice('en'), 'en');
    assert.equal(nextLocaleOnChoice('de'), 'en');
    assert.equal(nextLocaleOnChoice(null), 'en');
});

test('humanizeKey keeps a missing key readable', () => {
    assert.equal(humanizeKey('nav.items.myResults'), 'My Results');
    assert.equal(humanizeKey('some_key'), 'Some key');
});

test('meetings.schedule returns the action label, not the nested scheduleInfo object', () => {
    const enTranslator = translatorFor('en');
    const kmTranslator = translatorFor('km');

    // The action label "Schedule meeting" / "កំណត់កិច្ចប្រជុំ" should be returned
    assert.equal(enTranslator('meetings.schedule'), 'Schedule meeting');
    assert.equal(kmTranslator('meetings.schedule'), 'កំណត់កិច្ចប្រជុំ');

    // The nested scheduleInfo object should be accessible separately
    assert.equal(enTranslator('meetings.scheduleInfo.endsPrefix'), 'Ends');
    assert.equal(kmTranslator('meetings.scheduleInfo.endsPrefix'), 'បញ្ចប់');

    // Ensure no duplicate 'schedule' key exists inside meetings catalogue
    const walk = (source, prefix = '') => Object.entries(source).flatMap(([key, value]) => {
        const path = prefix ? `${prefix}.${key}` : key;
        return value && typeof value === 'object' ? walk(value, path) : [path];
    });
    const scheduleKeys = walk(en.meetings).filter((path) => path === 'schedule' || path === 'scheduleInfo.endsPrefix');
    assert.deepEqual(scheduleKeys, ['schedule', 'scheduleInfo.endsPrefix']);
});

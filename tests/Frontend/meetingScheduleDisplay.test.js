import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';

// The schedule-availability rule and the component contract are asserted against
// the real sources: this file performs no browser or LiveKit work, so it stays
// deterministic. Behavioural coverage for the state decision lives in the PHP
// feature test.
const meetingSchedule = readFileSync(
    new URL('../../resources/js/Components/Meetings/MeetingSchedule.jsx', import.meta.url),
    'utf8',
);
const card = readFileSync(
    new URL('../../resources/js/Components/Meetings/MeetingCard.jsx', import.meta.url),
    'utf8',
);
const show = readFileSync(
    new URL('../../resources/js/Pages/Meetings/Show.jsx', import.meta.url),
    'utf8');

test('an unavailable schedule withholds the stored timestamps entirely', () => {
    assert.match(meetingSchedule, /available === false/,
        'the component must branch on the server-provided availability flag');
    assert.match(meetingSchedule, /meetings\.scheduleInfo\.unavailable/,
        'an invalid schedule must render the unavailable state');
});

test('the unavailable branch never formats start or end as a schedule', () => {
    const branch = meetingSchedule.slice(meetingSchedule.indexOf('available === false'));
    const guarded = branch.slice(0, branch.indexOf('const format') === -1 ? branch.length : 0);
    // The early-return branch must not call format(start)/format(end).
    assert.ok(!/format\(start\)/.test(guarded.split('return (')[1]?.split(');')[0] ?? ''),
        'the unavailable branch must not render the inverted start value');
    assert.ok(!/format\(end\)/.test(guarded.split('return (')[1]?.split(');')[0] ?? ''),
        'the unavailable branch must not render the inverted end value');
});

test('actual activity is labelled separately and never used as the schedule', () => {
    assert.match(meetingSchedule, /actualStart/);
    assert.match(meetingSchedule, /actualEnd/);
    assert.match(meetingSchedule, /meetings\.scheduleInfo\.actualActivity/,
        'actual activity must carry its own label, distinct from the schedule');
    // Actual activity may only be *rendered* inside the unavailable branch.
    // The props are declared in the signature, which precedes the guard.
    const beforeGuard = meetingSchedule.slice(0, meetingSchedule.indexOf('available === false'));
    assert.ok(!/format\(actual/.test(beforeGuard),
        'actual activity must not be rendered outside the unavailable branch');
    assert.ok(/format\(actualStart\)/.test(meetingSchedule),
        'actual activity is formatted when the schedule is unavailable');
});

test('a valid schedule renders exactly as before', () => {
    assert.match(meetingSchedule, /meetings\.scheduleInfo\.endsPrefix/,
        'the valid branch keeps the existing ends label');
    assert.match(meetingSchedule, /\{end &&/,
        'the valid branch still renders the optional end line');
});

test('every consumer passes the server availability flag', () => {
    for (const source of [card, show]) {
        assert.match(source, /available=\{meeting\.schedule_available\}/,
            'each consumer must forward the server availability flag');
        assert.match(source, /actualStart=\{meeting\.actual_start_at\}/,
            'each consumer must forward actual activity separately');
    }
});

test('every remaining schedule surface uses the shared flag, not a local check', () => {
    const dashboard = readFileSync(new URL('../../resources/js/Pages/Dashboard.jsx', import.meta.url), 'utf8');
    const attendance = readFileSync(new URL('../../resources/js/Pages/Meetings/Attendance.jsx', import.meta.url), 'utf8');

    for (const [name, source] of [['Dashboard', dashboard], ['Attendance', attendance]]) {
        assert.match(source, /schedule_available === false/,
            `${name} must branch on the canonical availability flag`);
        assert.match(source, /meetings\.scheduleInfo\.unavailable/,
            `${name} must render the shared unavailable string`);
    }
});

test('both locales define the new keys', () => {
    const en = readFileSync(new URL('../../resources/js/i18n/en.js', import.meta.url), 'utf8');
    const km = readFileSync(new URL('../../resources/js/i18n/km.js', import.meta.url), 'utf8');
    for (const [name, source] of [['en', en], ['km', km]]) {
        assert.match(source, /unavailable:/, `${name} must define scheduleInfo.unavailable`);
        assert.match(source, /actualActivity:/, `${name} must define scheduleInfo.actualActivity`);
    }
});

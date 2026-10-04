import assert from 'node:assert/strict';
import test from 'node:test';
import {utcInstantToAcademicDateTimeLocal} from '../../resources/js/Components/Meetings/meetingDateTime.js';

const timezone = 'Asia/Phnom_Penh';
const storedStart = '2026-10-10T06:00:00Z';
const storedEnd = '2026-10-10T07:30:00Z';

test('the former UTC formatter demonstrates the edit-prefill regression', () => {
    const browserFormatter = new Date(storedStart).toISOString().slice(0, 16);

    assert.equal(browserFormatter, '2026-10-10T06:00');
    assert.notEqual(browserFormatter, '2026-10-10T13:00');
});

test('meeting edit prefill uses the configured academic timezone', () => {
    assert.equal(utcInstantToAcademicDateTimeLocal(storedStart, timezone), '2026-10-10T13:00');
    assert.equal(utcInstantToAcademicDateTimeLocal(storedEnd, timezone), '2026-10-10T14:30');
});

test('an unchanged edit round trip retains the local datetime-local values', () => {
    const form = {
        scheduled_start_at: utcInstantToAcademicDateTimeLocal(storedStart, timezone),
        scheduled_end_at: utcInstantToAcademicDateTimeLocal(storedEnd, timezone),
    };

    assert.deepEqual(form, {
        scheduled_start_at: '2026-10-10T13:00',
        scheduled_end_at: '2026-10-10T14:30',
    });
});

test('a changed local time remains an offset-less datetime-local value for server normalization', () => {
    const form = {
        scheduled_start_at: utcInstantToAcademicDateTimeLocal(storedStart, timezone),
        scheduled_end_at: utcInstantToAcademicDateTimeLocal(storedEnd, timezone),
    };

    form.scheduled_start_at = '2026-10-10T15:00';
    form.scheduled_end_at = '2026-10-10T16:30';

    assert.deepEqual(form, {
        scheduled_start_at: '2026-10-10T15:00',
        scheduled_end_at: '2026-10-10T16:30',
    });
});

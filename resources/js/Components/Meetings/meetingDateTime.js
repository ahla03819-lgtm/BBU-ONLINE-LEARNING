/**
 * Renders a server UTC instant for a datetime-local input in the academic
 * calendar timezone. datetime-local has no offset, so browser-local Date
 * getters and toISOString() would alter the wall-clock time being edited.
 */
export function utcInstantToAcademicDateTimeLocal(value, timezone) {
    const date = new Date(value);

    if (!value || !timezone || Number.isNaN(date.getTime())) return '';

    try {
        const parts = new Intl.DateTimeFormat('en-US', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            hourCycle: 'h23',
        }).formatToParts(date);
        const fields = Object.fromEntries(parts.map((part) => [part.type, part.value]));

        return `${fields.year}-${fields.month}-${fields.day}T${fields.hour}:${fields.minute}`;
    } catch {
        return '';
    }
}

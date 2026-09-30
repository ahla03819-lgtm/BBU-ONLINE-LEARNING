/**
 * Locale-safe descriptors for transient notices.
 *
 * A transient notice (toast, inline status line, connection banner, ...) usually
 * outlives the interaction that produced it. Storing an already translated string
 * in state freezes it in the locale that was active at the time, so the notice
 * stays Khmer after the user switches back to English.
 *
 * These helpers keep a *descriptor* in state instead:
 *
 *   noticeKey('meetingRoom.controlCenter.shareUnavailable')   // translatable
 *   noticeKey('meetingRoom.panels.peopleCount', {count: 3})   // with placeholders
 *   noticeRaw(problem.message)                                // server text
 *
 * `renderNotice(descriptor, t)` resolves it during render, so a locale change
 * updates a notice that is already on screen.
 *
 * Descriptor shapes:
 *   {key, params} -> resolved through t(key, params)
 *   {raw}         -> rendered verbatim (server messages, no key available)
 *
 * `null`/`undefined` mean "no notice", which keeps callers free to use
 * `setNotice(noticeKey(...))` without guarding the empty case first.
 */

/** Params may be lazy so nested translations stay locale-reactive too. */
const resolveParams = (params, t) => {
    if (params === null || params === undefined || typeof params !== 'object') {
        return params;
    }

    const entries = Object.entries(params).map(([name, value]) => [
        name,
        typeof value === 'function' ? value(t) : value,
    ]);

    return Object.fromEntries(entries);
};

/**
 * Descriptor for a translatable notice.
 *
 * @param {string} key      catalogue key
 * @param {object} [params] placeholder values; a function value is resolved
 *                          with `t` at render time
 * @returns {object|null}   `null` when the key is unusable
 */
export function noticeKey(key, params) {
    if (typeof key !== 'string' || key === '') {
        return null;
    }

    return {key, params: params ?? {}};
}

/**
 * Descriptor for a notice that has no translation key, such as a message
 * returned by the server or thrown by a third-party client.
 *
 * @param {string} text
 * @returns {object|null}   `null` when there is nothing to show
 */
export function noticeRaw(text) {
    if (text === null || text === undefined) {
        return null;
    }

    const value = typeof text === 'string' ? text : String(text);

    return value === '' ? null : {raw: value};
}

/** Accepts a descriptor or a plain value and returns a descriptor. */
export function notice(value) {
    if (value === null || value === undefined || typeof value === 'string') {
        return noticeRaw(value);
    }

    if (typeof value.key === 'string' || typeof value.raw === 'string') {
        return value;
    }

    return null;
}

export function isNotice(value) {
    return notice(value) !== null;
}

/**
 * Resolve a descriptor at render time.
 *
 * @param {object|string|null} value  descriptor, raw string or nullish
 * @param {Function} t                translator from `useTranslation()`
 * @returns {string}                  the localised text, or '' when absent
 */
export function renderNotice(value, t) {
    const descriptor = notice(value);

    if (descriptor === null) {
        return '';
    }

    if (typeof descriptor.raw === 'string') {
        return descriptor.raw;
    }

    if (typeof t !== 'function' || typeof descriptor.key !== 'string') {
        return '';
    }

    return t(descriptor.key, resolveParams(descriptor.params, t));
}

/** First notice that resolves to something, mirroring `a || b` in JSX. */
export function renderFirstNotice(values, t) {
    for (const value of values ?? []) {
        const text = renderNotice(value, t);

        if (text !== '') {
            return text;
        }
    }

    return '';
}

/** Whether a notice should be shown, without resolving it. */
export function hasNotice(value) {
    const descriptor = notice(value);

    if (descriptor === null) {
        return false;
    }

    return typeof descriptor.raw === 'string' ? descriptor.raw !== '' : descriptor.key !== '';
}

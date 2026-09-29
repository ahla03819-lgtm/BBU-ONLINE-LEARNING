/**
 * Dependency-free translation resolution.
 *
 * English is the only fallback: a key missing from the active locale always
 * resolves to the English string, and a key missing everywhere degrades to a
 * readable label derived from the key itself. Raw `undefined`/`null` values are
 * never rendered.
 *
 * Supported value shapes:
 *   string                       -> rendered as-is
 *   {one, other} + {count: n}    -> English pluralisation, single value otherwise
 *
 * Placeholders use `{name}` and are replaced from the values object.
 */

export const FALLBACK_LOCALE = 'en';

export const SUPPORTED_LOCALES = ['en', 'km'];

const has = (source, key) => source !== null
    && typeof source === 'object'
    && Object.prototype.hasOwnProperty.call(source, key);

export function resolveKey(source, key) {
    if (source === null || source === undefined || typeof source !== 'object' || typeof key !== 'string' || key === '') {
        return undefined;
    }

    if (has(source, key)) {
        return source[key];
    }

    let current = source;

    for (const segment of key.split('.')) {
        if (current === null || typeof current !== 'object' || ! has(current, segment)) {
            return undefined;
        }

        current = current[segment];
    }

    return current;
}

export function humanizeKey(key) {
    const segment = String(key).split('.').pop() || String(key);

    return segment
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z0-9])([A-Z])/g, '$1 $2')
        .replace(/^./, (character) => character.toUpperCase());
}

export function isSupportedLocale(locale) {
    return typeof locale === 'string' && SUPPORTED_LOCALES.includes(locale);
}

export function normalizeLocale(locale) {
    return isSupportedLocale(locale) ? locale : FALLBACK_LOCALE;
}

function interpolate(template, values) {
    if (typeof values !== 'object' || values === null) {
        return template;
    }

    return template.replace(/\{(\w+)\}/g, (match, name) => (
        values[name] === undefined || values[name] === null ? match : String(values[name])
    ));
}

function selectValue(entry, values) {
    if (typeof entry === 'string') {
        return entry;
    }

    if (entry === null || typeof entry !== 'object') {
        return undefined;
    }

    const count = typeof values?.count === 'number' ? values.count : null;

    if (count !== null && has(entry, 'one') && has(entry, 'other')) {
        return count === 1 ? entry.one : entry.other;
    }

    for (const value of Object.values(entry)) {
        if (typeof value === 'string') {
            return value;
        }
    }

    return undefined;
}

/**
 * Build a `t(key, values)` function bound to a locale and message catalogue.
 *
 * @param {object}   options
 * @param {object}   options.messages        map of locale -> catalogue
 * @param {string}   options.locale          active locale
 * @param {string}   [options.fallbackLocale]
 * @param {Function} [options.onMissingKey]  dev-time reporting hook, called with
 *                                           (key, activeLocale, resolvedByFallback)
 */
export function createTranslator({messages, locale, fallbackLocale = FALLBACK_LOCALE, onMissingKey} = {}) {
    const active = normalizeLocale(locale);
    const fallback = normalizeLocale(fallbackLocale);
    const reported = new Set();

    return function translate(key, values) {
        if (typeof key !== 'string' || key === '') {
            return '';
        }

        const candidates = active === fallback ? [active] : [active, fallback];

        for (let index = 0; index < candidates.length; index += 1) {
            const resolved = selectValue(resolveKey(messages?.[candidates[index]], key), values);

            if (typeof resolved === 'string') {
                if (index > 0 && typeof onMissingKey === 'function' && ! reported.has(key)) {
                    // A key the active locale is missing but English still covers.
                    reported.add(key);
                    onMissingKey(key, active, true);
                }

                return interpolate(resolved, values);
            }
        }

        if (typeof onMissingKey === 'function' && ! reported.has(key)) {
            reported.add(key);
            onMissingKey(key, active, false);
        }

        return humanizeKey(key);
    };
}

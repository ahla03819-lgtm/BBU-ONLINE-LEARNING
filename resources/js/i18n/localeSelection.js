/**
 * Pure locale selection rules.
 *
 * The account preference shared by Inertia is authoritative. A locale the user
 * just chose is applied immediately and stays visible until the server confirms
 * it, so an in-flight navigation that still carries the previous value can never
 * revert the interface under the user.
 */

import {normalizeLocale} from './translate.js';

export const nextLocaleOnChoice = (next) => normalizeLocale(next);

/**
 * Reconcile the visible locale with a freshly received server preference.
 *
 * @param {object} state
 * @param {string|null} state.locale     currently displayed locale
 * @param {string|null} state.chosen     locale awaiting server confirmation
 * @param {string} state.serverLocale    preference shared by the server
 * @returns {{locale: string, chosen: string|null, confirmed: boolean}}
 */
export function syncLocaleFromServer({locale, chosen, serverLocale}) {
    const server = normalizeLocale(serverLocale);

    if (chosen === null || chosen === undefined) {
        return {locale: server, chosen: null, confirmed: true};
    }

    if (server === chosen) {
        return {locale: server, chosen: null, confirmed: true};
    }

    // Keep the user's pending choice; the server has not caught up yet.
    return {locale: normalizeLocale(locale), chosen, confirmed: false};
}

/**
 * Whether a user choice still needs to be written to the server.
 */
export function shouldPersistLocale({locale, chosen}) {
    return chosen !== null && chosen !== undefined && chosen === locale;
}

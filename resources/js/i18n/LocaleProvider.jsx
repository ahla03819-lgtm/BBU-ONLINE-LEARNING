import React, {createContext, useCallback, useContext, useEffect, useMemo, useRef, useState} from 'react';
import {usePage} from '@inertiajs/react';
import en from './en';
import km from './km';
import {createTranslator, FALLBACK_LOCALE, isSupportedLocale} from './translate';
import {nextLocaleOnChoice, shouldPersistLocale, syncLocaleFromServer} from './localeSelection';

const messages = {en, km};

const LocaleContext = createContext(null);

const readCsrfToken = () => {
    try {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    } catch {
        return '';
    }
};

const fallbackContext = {
    locale: FALLBACK_LOCALE,
    setLocale: () => {},
    t: (key) => key,
    isSaving: false,
};

/**
 * Holds the application language.
 *
 * The account preference shared by Inertia is the source of truth and survives
 * refresh, logout/login and other devices. An explicit user choice is applied
 * immediately and persisted in the background, so the interface never waits for
 * a navigation to change language.
 */
export function LocaleProvider({children}) {
    const {locale: sharedLocale} = usePage().props ?? {};
    const serverLocale = isSupportedLocale(sharedLocale) ? sharedLocale : FALLBACK_LOCALE;

    const [locale, setLocaleState] = useState(serverLocale);
    const [isSaving, setIsSaving] = useState(false);

    // The locale the user explicitly chose and that is not yet reflected by the
    // server. A stale Inertia response must never undo a visible choice.
    const chosen = useRef(null);

    useEffect(() => {
        const next = syncLocaleFromServer({locale, chosen: chosen.current, serverLocale});

        chosen.current = next.chosen;
        setLocaleState(next.locale);
    }, [serverLocale]);

    useEffect(() => {
        document.documentElement.lang = locale;
    }, [locale]);

    useEffect(() => {
        if (! shouldPersistLocale({locale, chosen: chosen.current})) {
            return;
        }

        const controller = new AbortController();
        const requested = locale;
        setIsSaving(true);

        fetch('/my-account/locale', {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': readCsrfToken(),
            },
            body: JSON.stringify({locale: requested}),
            signal: controller.signal,
        })
            .then((response) => {
                if (! response.ok) {
                    throw new Error('locale-persistence-failed');
                }
            })
            .catch((problem) => {
                // An aborted request is not a failure: this effect is cleaned up
                // whenever the locale changes again, and the newer request owns
                // the outcome.
                if (problem?.name === 'AbortError') {
                    return;
                }

                // The preference could not be stored, so the server value stays
                // authoritative and the interface returns to it.
                chosen.current = null;
                setLocaleState(serverLocale);
            })
            .finally(() => {
                setIsSaving(false);
            });

        return () => controller.abort();
    }, [locale]);

    const setLocale = useCallback((next) => {
        chosen.current = nextLocaleOnChoice(next);
        setLocaleState(chosen.current);
    }, []);

    const t = useMemo(() => createTranslator({
        messages,
        locale,
        fallbackLocale: FALLBACK_LOCALE,
        onMissingKey: (key, activeLocale, resolvedByFallback) => {
            if (! import.meta.env?.DEV) {
                return;
            }

            console.warn(resolvedByFallback
                ? `[i18n] Missing "${key}" translation for locale "${activeLocale}". Falling back to English.`
                : `[i18n] Missing "${key}" translation for locale "${activeLocale}" and for the English fallback.`);
        },
    }), [locale]);

    const value = useMemo(() => ({locale, setLocale, t, isSaving}), [locale, setLocale, t, isSaving]);

    return <LocaleContext.Provider value={value}>{children}</LocaleContext.Provider>;
}

export function useTranslation() {
    return useContext(LocaleContext) ?? fallbackContext;
}

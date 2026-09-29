import React, {useEffect, useRef, useState} from 'react';
import Icon from './Icon';
import {useTranslation} from '../../i18n/LocaleProvider';
import unitedKingdomFlag from '../../assets/flag-united-kingdom.png';
import cambodiaFlag from '../../assets/flag-cambodia.png';

const options = [
    {value: 'en', label: 'English', short: 'EN', flag: unitedKingdomFlag},
    {value: 'km', label: 'ខ្មែរ', short: 'KM', flag: cambodiaFlag},
];

/**
 * Global language selector for the authenticated header.
 *
 * Writing to the shared account preference, so the choice follows the user to
 * other pages and devices instead of living in one component.
 */
export default function LanguageSwitcher() {
    const {locale, setLocale, t} = useTranslation();
    const [open, setOpen] = useState(false);
    const wrapperRef = useRef(null);
    const triggerRef = useRef(null);
    const optionRefs = useRef([]);

    const active = options.find((option) => option.value === locale) ?? options[0];

    useEffect(() => {
        if (! open) {
            return;
        }

        const close = (event) => {
            if (! wrapperRef.current?.contains(event.target)) {
                setOpen(false);
            }
        };
        const escape = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
                triggerRef.current?.focus();
            }
        };

        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    useEffect(() => {
        if (open) {
            requestAnimationFrame(() => optionRefs.current[options.findIndex((option) => option.value === locale)]?.focus());
        }
    }, [open, locale]);

    const choose = (value) => {
        setOpen(false);
        triggerRef.current?.focus();

        if (value !== locale) {
            setLocale(value);
        }
    };

    const onTriggerKeyDown = (event) => {
        if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            setOpen(true);
        }
    };

    const onMenuKeyDown = (event) => {
        if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') {
            return;
        }

        event.preventDefault();
        const current = options.findIndex((option) => option.value === locale);
        const next = event.key === 'ArrowDown'
            ? (current + 1) % options.length
            : (current - 1 + options.length) % options.length;
        optionRefs.current[next]?.focus();
    };

    return <div className="relative" ref={wrapperRef}>
        <button
            ref={triggerRef}
            type="button"
            onClick={() => setOpen((value) => ! value)}
            onKeyDown={onTriggerKeyDown}
            className="inline-flex min-h-9 items-center gap-2 rounded-lg border border-slate-200/80 bg-white px-2.5 py-1 text-sm font-semibold leading-6 text-slate-700 shadow-sm transition hover:border-sky-200 hover:bg-sky-50 hover:text-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600"
            aria-haspopup="menu"
            aria-expanded={open}
            aria-label={`${t('language.openMenu')} — ${t('language.current', {language: t(`language.${active.value}`)})}`}
        >
            <img src={active.flag} alt="" aria-hidden="true" className="h-6 w-6 shrink-0 rounded-full object-cover"/>
            <span lang={active.value} className="max-w-24 truncate">{active.label}</span>
        </button>
        {open && <div
            className="absolute right-0 top-[calc(100%+.55rem)] z-30 w-56 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2.5 shadow-xl shadow-slate-900/10"
            role="menu"
            aria-label={t('language.choose')}
            onKeyDown={onMenuKeyDown}
        >
            <p className="px-3 pb-2 pt-1.5 text-xs font-semibold uppercase tracking-wider text-slate-500">{t('language.label')}</p>
            {options.map((option, index) => {
                const selected = option.value === locale;

                return <button
                    key={option.value}
                    ref={(node) => { optionRefs.current[index] = node; }}
                    type="button"
                    role="menuitemradio"
                    aria-checked={selected}
                    onClick={() => choose(option.value)}
                    className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-base font-medium leading-7 transition focus:outline-none focus:ring-2 focus:ring-sky-600 ${selected ? 'bg-sky-50 text-sky-900' : 'text-slate-700 hover:bg-sky-50 hover:text-sky-900'}`}
                >
                    <img src={option.flag} alt="" aria-hidden="true" className="h-7 w-7 shrink-0 rounded-full object-cover"/>
                    <span lang={option.value} className="min-w-0 flex-1 truncate">{option.label}</span>
                    <span className="shrink-0 rounded-md bg-slate-100 px-1.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-slate-500">{option.short}</span>
                    <span className="flex h-5 w-5 shrink-0 items-center justify-center text-sky-700" aria-hidden="true">{selected && <Icon name="present" className="h-4 w-4"/>}</span>
                </button>;
            })}
        </div>}
    </div>;
}

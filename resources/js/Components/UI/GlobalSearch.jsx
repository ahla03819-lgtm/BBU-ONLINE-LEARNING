import React, {useEffect, useRef, useState} from 'react';
import {router} from '@inertiajs/react';
import Icon from './Icon';
import UserAvatar from './UserAvatar';
import {useTranslation} from '../../i18n/LocaleProvider';

const categories = ['all', 'people', 'messages', 'files', 'classes', 'meetings', 'assignments', 'announcements'];
const labelKeys = {people: 'search.people', messages: 'search.messages', files: 'search.files', classes: 'search.classes', meetings: 'search.meetings', assignments: 'search.assignments', announcements: 'search.announcements'};
const icons = {people: 'users', messages: 'messages', files: 'clipboard', classes: 'school', meetings: 'video', assignments: 'book', announcements: 'bell'};

export default function GlobalSearch() {
    const {t} = useTranslation();
    const [open, setOpen] = useState(false); const [query, setQuery] = useState(''); const [category, setCategory] = useState('all'); const [results, setResults] = useState({}); const [loading, setLoading] = useState(false); const [error, setError] = useState(''); const [active, setActive] = useState(0);

    const inputRef = useRef(null); const panelRef = useRef(null); const controllerRef = useRef(null);
    // Search results must not refetch when only the interface language changes.
    const translateRef = useRef(t);
    translateRef.current = t;
    const items = Object.values(results).flat();
    useEffect(() => {
        const shortcut = (event) => {
            const editable = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
            if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k' && !editable) { event.preventDefault(); setOpen(true); requestAnimationFrame(() => inputRef.current?.focus()); }
            if (event.key === 'Escape') setOpen(false);
        };
        const outside = (event) => { if (panelRef.current && !panelRef.current.contains(event.target)) setOpen(false); };
        document.addEventListener('keydown', shortcut); document.addEventListener('mousedown', outside);
        return () => { document.removeEventListener('keydown', shortcut); document.removeEventListener('mousedown', outside); controllerRef.current?.abort(); };
    }, []);
    useEffect(() => {
        const term = query.trim(); setActive(0); setError('');
        if (term.length < 2) { controllerRef.current?.abort(); setResults({}); setLoading(false); return; }
        const timeout = window.setTimeout(async () => {
            controllerRef.current?.abort(); const controller = new AbortController(); controllerRef.current = controller; setLoading(true);
            try {
                const response = await fetch(`/global-search?${new URLSearchParams({q: term, category})}`, {headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', signal: controller.signal});
                if (!response.ok) throw new Error(response.status === 403 ? translateRef.current('search.unavailable') : translateRef.current('search.failed'));
                const payload = await response.json(); if (!controller.signal.aborted) setResults(payload.results || {});
            } catch (exception) { if (exception.name !== 'AbortError') setError(exception.message || translateRef.current('search.failed')); } finally { if (!controller.signal.aborted) setLoading(false); }
        }, 300);
        return () => window.clearTimeout(timeout);
    }, [query, category]);
    const navigate = (item) => { if (item) { setOpen(false); router.visit(item.url); } };
    const onKeyDown = (event) => {
        if (!items.length) return; if (event.key === 'ArrowDown') { event.preventDefault(); setActive((index) => (index + 1) % items.length); } else if (event.key === 'ArrowUp') { event.preventDefault(); setActive((index) => (index - 1 + items.length) % items.length); } else if (event.key === 'Enter') { event.preventDefault(); navigate(items[active]); }
    };
    return <div className="relative flex-none lg:max-w-sm lg:flex-1" ref={panelRef}>
        <button type="button" onClick={() => { setOpen(true); requestAnimationFrame(() => inputRef.current?.focus()); }} className="inline-flex h-8 w-8 items-center justify-center rounded-lg text-sky-700 transition hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600 lg:hidden" aria-label={t('topbar.searchLabel')}><Icon name="search" className="h-4 w-4"/></button>
        <span className="pointer-events-none absolute inset-y-0 left-3 z-10 flex items-center text-sky-700" aria-hidden="true"><Icon name="search" className="h-4 w-4"/></span>
        <input ref={inputRef} value={query} onFocus={() => setOpen(true)} onChange={(event) => { setQuery(event.target.value); setOpen(true); }} onKeyDown={onKeyDown} placeholder={t('topbar.searchPlaceholder')} aria-label={t('topbar.searchLabel')} aria-expanded={open} aria-controls="global-search-results" className={`w-full rounded-lg border border-slate-200 bg-sky-50/55 py-2 pl-9 pr-12 text-xs text-slate-700 outline-none transition placeholder:text-slate-500 focus:border-sky-500 focus:ring-2 focus:ring-sky-100 ${open ? 'absolute left-0 top-0 z-20 min-w-[min(22rem,calc(100vw-2rem))] lg:static lg:min-w-0' : 'hidden lg:block'}`}/>
        {query && <button type="button" onClick={() => { setQuery(''); inputRef.current?.focus(); }} className="absolute inset-y-0 right-2 text-slate-400 hover:text-slate-700" aria-label={t('search.clear')}>×</button>}
        {open && <section id="global-search-results" role="dialog" aria-label={t('search.dialogLabel')} className="absolute left-0 top-[calc(100%+.55rem)] z-40 w-[min(38rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10"><div className="flex gap-1 overflow-x-auto border-b border-slate-100 px-3 py-2">{categories.map((name) => <button type="button" key={name} onClick={() => setCategory(name)} className={`whitespace-nowrap rounded-lg px-2.5 py-1 text-xs font-bold transition ${category === name ? 'bg-sky-800 text-white' : 'text-slate-600 hover:bg-sky-50'}`}>{name === 'all' ? t('search.all') : t(labelKeys[name])}</button>)}</div><div className="max-h-[28rem] overflow-y-auto p-2">{query.trim().length < 2 && <p className="px-3 py-7 text-center text-sm text-slate-500">{t('search.hint')}</p>}{loading && <p className="px-3 py-7 text-center text-sm text-slate-500">{t('search.searching')}</p>}{error && <p role="alert" className="px-3 py-7 text-center text-sm text-rose-700">{error}</p>}{!loading && !error && query.trim().length >= 2 && !items.length && <p className="px-3 py-7 text-center text-sm text-slate-500">{t('search.noResults')}</p>}{!loading && Object.entries(results).map(([name, group]) => group.length ? <div key={name} className="py-1"><h2 className="px-3 pb-1 pt-2 text-[11px] font-black uppercase tracking-wider text-slate-400">{t(labelKeys[name])}</h2>{group.map((item) => { const index = items.indexOf(item); return <button type="button" key={`${name}-${item.url}-${item.title}`} onClick={() => navigate(item)} onMouseEnter={() => setActive(index)} className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left transition ${index === active ? 'bg-sky-50' : 'hover:bg-slate-50'}`}><span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-sky-50 text-sky-700">{item.avatarUrl ? <UserAvatar name={item.title} avatarUrl={item.avatarUrl} size="sm" alt=""/> : <Icon name={icons[name]} className="h-4 w-4"/>}</span><span className="min-w-0"><span className="block truncate text-sm font-bold text-slate-800">{item.title}</span>{item.description && <span className="block truncate text-xs text-slate-500">{item.description}</span>}{item.meta?.conversation && <span className="block truncate text-[11px] text-slate-400">{item.meta.conversation}</span>}</span></button>; })}</div> : null)}</div><p className="border-t border-slate-100 px-4 py-2 text-[11px] text-slate-400">{t('search.keyboardHint')}</p></section>}
    </div>;
}

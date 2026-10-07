import React, {useEffect, useRef, useState} from 'react';
import {Link, usePage} from '@inertiajs/react';
import UserAvatar from './UserAvatar';
import Icon from './Icon';
import bbuOfficialLogo from '../../assets/bbu-official-logo.png';
import {useTranslation} from '../../i18n/LocaleProvider';
import {isNavigationItemActive} from './navigationState';

const desktopQuery = '(min-width: 1024px)';

export default function AppSidebar({open, desktopOpen, onClose, onDesktopToggle, navigation, user, roleLabel, returnFocusRef}) {
    const component = usePage().component;
    const {t} = useTranslation();
    const asideRef = useRef(null);
    const closeButtonRef = useRef(null);
    const [isDesktop, setIsDesktop] = useState(() => typeof window !== 'undefined' && window.matchMedia(desktopQuery).matches);
    const visible = isDesktop ? desktopOpen : open;

    useEffect(() => {
        const media = window.matchMedia(desktopQuery);
        const update = (event) => setIsDesktop(event.matches);
        setIsDesktop(media.matches);
        media.addEventListener('change', update);
        return () => media.removeEventListener('change', update);
    }, []);

    useEffect(() => {
        if (isDesktop || !open) {
            return;
        }

        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => closeButtonRef.current?.focus());

        const handleKeyDown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                onClose();
                returnFocusRef?.current?.focus();
                return;
            }

            if (event.key !== 'Tab') {
                return;
            }

            const focusable = asideRef.current?.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])');
            if (!focusable?.length) {
                return;
            }

            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.body.style.overflow = previousOverflow;
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [isDesktop, onClose, open, returnFocusRef]);

    const closeMobileNavigation = () => {
        onClose();
        requestAnimationFrame(() => returnFocusRef?.current?.focus());
    };

    return <>
        <button type="button" tabIndex={open ? 0 : -1} aria-label={t('nav.labels.closeNavigation')} onClick={closeMobileNavigation} className={`fixed inset-0 z-30 bg-slate-900/45 transition-opacity lg:hidden ${open ? 'visible opacity-100' : 'invisible opacity-0'}`}/>
        <aside ref={asideRef} inert={!visible} aria-hidden={!visible} aria-label={t('nav.labels.primary')} className={`fixed inset-y-0 left-0 z-40 flex h-dvh w-[min(18rem,calc(100vw-2rem))] flex-col border-r border-slate-200 bg-white px-3 pb-[max(1rem,env(safe-area-inset-bottom))] pt-[max(1rem,env(safe-area-inset-top))] shadow-xl transition-transform duration-200 lg:w-56 lg:py-4 lg:shadow-none ${open ? 'translate-x-0' : '-translate-x-full'} ${desktopOpen ? 'lg:translate-x-0' : 'lg:-translate-x-full'}`}>
            <div className="flex items-center gap-2.5 px-2"><img src={bbuOfficialLogo} alt="Build Bright University" className="h-11 w-9 shrink-0 object-contain"/><div className="min-w-0"><p className="font-bold tracking-tight text-slate-900">BBU</p><p className="truncate text-[10px] font-semibold tracking-wide text-slate-500">ONLINE LEARNING</p></div><button type="button" onClick={onDesktopToggle} className="ml-auto hidden min-h-11 min-w-11 items-center justify-center rounded-lg text-sky-700 transition hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600 lg:inline-flex" aria-label={t('nav.labels.hideSidebar')} aria-expanded="true"><Icon name="menu" className="h-4 w-4"/></button><button ref={closeButtonRef} type="button" onClick={closeMobileNavigation} className="ml-auto inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg text-xl text-slate-500 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600 lg:hidden" aria-label={t('nav.labels.closeMenu')}>×</button></div>
            <nav className="mt-4 flex-1 space-y-4 overflow-y-auto overscroll-contain pr-1">{navigation.map((group) => <section key={group.label}><p className="px-2 text-[10px] font-bold uppercase tracking-wider text-slate-400">{group.label}</p><div className="mt-1.5 space-y-0.5">{group.items.map((item) => {
                const active = isNavigationItemActive(component, item.component);
                return item.href ? <Link onClick={closeMobileNavigation} key={item.label} href={item.href} aria-current={active ? 'page' : undefined} className={`relative flex min-h-11 items-center gap-2.5 rounded-lg px-2.5 text-[13px] font-semibold transition ${active ? 'bg-sky-50 text-sky-800 before:absolute before:inset-y-1.5 before:left-0 before:w-0.5 before:rounded-full before:bg-sky-700' : 'text-slate-600 hover:bg-sky-50/70 hover:text-sky-800'}`}><span className={`inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md ${active ? 'text-sky-700' : 'text-slate-500'}`}><Icon name={item.icon} className="h-4 w-4"/></span><span className="min-w-0 flex-1 break-words">{item.label}</span>{item.badge > 0 && <span className="ml-auto rounded-full bg-rose-600 px-1.5 py-0.5 text-[10px] text-white">{item.badge > 99 ? '99+' : item.badge}</span>}</Link> : <p key={item.label} className="flex min-h-11 items-center gap-2.5 rounded-lg px-2.5 text-[13px] text-slate-400"><span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md"><Icon name={item.icon} className="h-4 w-4"/></span><span className="min-w-0 break-words">{item.label}</span></p>;
            })}</div></section>)}</nav>
            <div className="mt-3 rounded-xl border border-slate-200 bg-slate-50/70 p-2.5"><Link href="/my-account" onClick={closeMobileNavigation} className="flex min-h-11 w-full cursor-pointer items-center gap-2.5 rounded-lg px-1 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-600" aria-label={t('topbar.myAccount')}><UserAvatar name={user.name} avatarUrl={user.avatar_url} size="sm"/><div className="min-w-0"><p className="truncate text-xs font-bold text-slate-800">{user.name}</p>{roleLabel && <p className="truncate text-[10px] text-slate-500">{roleLabel}</p>}</div></Link><Link href="/logout" method="post" as="button" className="mt-1.5 flex min-h-11 w-full items-center gap-2 rounded-lg px-2 text-left text-xs font-bold text-rose-600 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-500"><Icon name="logout" className="h-3.5 w-3.5"/>{t('topbar.signOut')}</Link></div>
        </aside>
    </>;
}

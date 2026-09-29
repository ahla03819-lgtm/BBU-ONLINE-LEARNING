import React, {useEffect, useRef, useState} from 'react';
import {Link, router} from '@inertiajs/react';
import Icon from './Icon';
import UserAvatar from './UserAvatar';
import LanguageSwitcher from './LanguageSwitcher';
import NotificationItem from '../Notifications/NotificationItem';
import bbuOfficialLogo from '../../assets/bbu-official-logo.png';
import GlobalSearch from './GlobalSearch';
import {useTranslation} from '../../i18n/LocaleProvider';

export default function AppTopbar({title, onMenu, onSidebarToggle, sidebarExpanded, notificationUrl, unreadCount, notificationPreview = [], user}) {
    const {t} = useTranslation();
    const [menuOpen, setMenuOpen] = useState(false);
    const [activityOpen, setActivityOpen] = useState(false);
    const menuRef = useRef(null);
    const activityRef = useRef(null);

    useEffect(() => {
        const close = (event) => {
            if (!menuRef.current?.contains(event.target)) setMenuOpen(false);
            if (!activityRef.current?.contains(event.target)) setActivityOpen(false);
        };
        const escape = (event) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
                setActivityOpen(false);
            }
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', escape);
        };
    }, []);

    return <header className="sticky top-0 z-20 border-b border-slate-200 bg-white/92 backdrop-blur">
        <div className="flex h-14 items-center gap-3 px-4 sm:px-5">
            <button type="button" onClick={onMenu} className="rounded-lg p-2 text-sky-700 hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600 lg:hidden" aria-label={t('nav.labels.openNavigation')}><Icon name="menu" className="h-4 w-4"/></button>
            <img src={bbuOfficialLogo} alt="Build Bright University" className="h-8 w-6 shrink-0 object-contain lg:hidden"/>
            {!sidebarExpanded && <button type="button" onClick={onSidebarToggle} className="hidden rounded-lg p-2 text-sky-700 transition hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600 lg:inline-flex" aria-label={t('nav.labels.showSidebar')} aria-expanded="false"><Icon name="menu" className="h-4 w-4"/></button>}
            <GlobalSearch/>
            <h1 className="min-w-0 truncate text-sm font-bold text-slate-800 lg:hidden">{title}</h1>
            <div className="ml-auto flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50/75 p-1 sm:gap-2.5">
                {notificationUrl && <div className="relative" ref={activityRef}>
                    <button type="button" onClick={() => setActivityOpen((open) => !open)} className="relative inline-flex h-8 w-8 items-center justify-center rounded-lg bg-white text-slate-600 shadow-sm transition hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600" aria-label={t('topbar.openActivity')} aria-haspopup="dialog" aria-expanded={activityOpen}><Icon name="bell" className="h-4 w-4"/>{unreadCount > 0 && <span className="absolute -right-1 -top-1 min-w-4 rounded-full bg-rose-600 px-1 text-center text-[9px] font-bold text-white">{unreadCount > 99 ? '99+' : unreadCount}</span>}</button>
                    {activityOpen && <section className="absolute right-0 top-[calc(100%+.55rem)] z-30 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-900/10" role="dialog" aria-label={t('topbar.activity')}><div className="flex items-center justify-between border-b border-slate-100 px-4 py-3"><div><h2 className="text-sm font-black text-slate-900">{t('topbar.activity')}</h2><p className="text-xs text-slate-500">{unreadCount ? t('notifications.unreadUpdate', {count: unreadCount}) : t('notifications.youAreAllCaughtUp')}</p></div><Link href={notificationUrl} onClick={() => setActivityOpen(false)} className="text-xs font-bold text-sky-800 hover:text-sky-950">{t('common.viewAll')}</Link></div><div className="max-h-[22rem] space-y-1 overflow-y-auto p-2">{notificationPreview.length ? notificationPreview.map((notification) => <NotificationItem key={notification.public_id} notification={notification} compact onNavigate={() => setActivityOpen(false)}/>) : <div className="px-4 py-8 text-center text-sm text-slate-500">{t('topbar.noActivity')}</div>}</div><Link href={notificationUrl} onClick={() => setActivityOpen(false)} className="block border-t border-slate-100 px-4 py-3 text-center text-sm font-bold text-sky-800 transition hover:bg-sky-50">{t('topbar.viewAllActivity')}</Link></section>}
                </div>}
                <div className="hidden h-6 border-l border-slate-200 sm:block"/>
                <LanguageSwitcher/>
                <div className="hidden h-6 border-l border-slate-200 sm:block"/>
                <div className="relative" ref={menuRef}>
                    <button type="button" className="flex items-center gap-2 rounded-lg px-1 py-0.5 text-left transition hover:bg-white focus:outline-none focus:ring-2 focus:ring-sky-600" onClick={() => setMenuOpen((open) => !open)} aria-haspopup="menu" aria-expanded={menuOpen} aria-label={t('topbar.openAccountMenu')}><UserAvatar name={user.name} avatarUrl={user.avatar_url} size="sm" alt=""/><span className="hidden max-w-28 truncate text-xs font-bold text-slate-700 sm:block">{user.name}</span><span className="hidden text-xs text-sky-700 sm:block" aria-hidden="true">⌄</span></button>
                    {menuOpen && <div className="absolute right-0 top-[calc(100%+.55rem)] z-30 w-64 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10" role="menu" aria-label={t('topbar.accountMenu')}><div className="border-b border-slate-100 px-3 py-2.5"><p className="truncate text-sm font-bold text-slate-900">{user.name}</p><p className="mt-0.5 truncate text-xs text-slate-500">{user.email}</p></div><div className="py-1"><Link href="/my-account" onClick={() => setMenuOpen(false)} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-sky-50 hover:text-sky-800" role="menuitem"><Icon name="user" className="h-4 w-4"/>{t('topbar.myAccount')}</Link><Link href="/my-account/appearance" onClick={() => setMenuOpen(false)} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-sky-50 hover:text-sky-800" role="menuitem"><Icon name="settings" className="h-4 w-4"/>{t('topbar.settings')}</Link></div><div className="border-t border-slate-100 pt-1"><button type="button" onClick={() => router.post('/logout')} className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold text-red-700 transition hover:bg-red-50" role="menuitem"><Icon name="logout" className="h-4 w-4"/>{t('topbar.signOut')}</button></div></div>}
                </div>
            </div>
        </div>
    </header>;
}

import React, {useEffect, useState} from 'react';
import {Head, router} from '@inertiajs/react';
import {useTranslation} from '../../i18n/LocaleProvider';
import {GuestShell} from './Join';
import {guestTerminalStatuses, startGuestStatusPolling} from './guestWaitingRoomPolling';

export default function WaitingRoom({session: initialSession, meeting, status_url: statusUrl, leave_url: leaveUrl, poll_interval_ms: pollIntervalMs}) {
    const {t} = useTranslation();
    const [session, setSession] = useState(initialSession);
    const [networkError, setNetworkError] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const [retry, setRetry] = useState(0);

    useEffect(() => {
        if (guestTerminalStatuses.has(session.status)) return undefined;
        return startGuestStatusPolling({
            intervalMs: pollIntervalMs,
            fetchStatus: async () => {
                const response = await fetch(statusUrl, {headers: {Accept: 'application/json'}});
                if (!response.ok) throw new Error('Guest status unavailable');
                const data = await response.json();
                return data.session;
            },
            onStatus: (nextSession) => { setNetworkError(false); setSession(nextSession); },
            onUnavailable: () => setNetworkError(true),
        });
    }, [pollIntervalMs, retry, session.status, statusUrl]);

    useEffect(() => {
        if (guestTerminalStatuses.has(session.status) && session.status !== 'rejected' && session.terminal_url) {
            window.location.replace(session.terminal_url);
        }
    }, [session.status, session.terminal_url]);

    const leave = async () => {
        if (!session.can_leave || leaving) return;
        setLeaving(true);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(leaveUrl, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error('Unable to leave');
            setSession(data.session);
            if (data.redirect_url) window.location.replace(data.redirect_url);
        } catch { setNetworkError(true); } finally { setLeaving(false); }
    };

    return <GuestShell title={t('guestMeeting.waiting.title')}>
        <p className="text-sm font-semibold uppercase tracking-[.18em] text-indigo-700">{t('guestMeeting.brand')}</p>
        <h1 className="mt-3 text-3xl font-bold tracking-tight text-slate-900">{t('guestMeeting.waiting.title')}</h1>
        {meeting.title && <p className="mt-2 text-sm font-medium text-slate-600">{meeting.title}</p>}
        <div className="mt-7 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-5 text-center" aria-live="polite">
            <p className="text-sm text-slate-600">{t('guestMeeting.waiting.greeting', {name: session.display_name})}</p>
            <p className="mt-3 text-lg font-bold text-slate-900">{t(`guestMeeting.status.${session.status}.title`)}</p>
            <p className="mt-2 text-sm leading-6 text-slate-600">{t(`guestMeeting.status.${session.status}.description`)}</p>
        </div>
        {networkError && <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="alert"><p>{t('guestMeeting.waiting.networkError')}</p><button type="button" className="mt-3 text-sm font-semibold underline" onClick={() => { setNetworkError(false); setRetry((value) => value + 1); }}>{t('common.retry')}</button></div>}
        {session.can_leave && <button type="button" className="btn-secondary mt-6 w-full justify-center" disabled={leaving} onClick={leave}>{leaving ? t('guestMeeting.waiting.leaving') : t('guestMeeting.waiting.leave')}</button>}
        {session.status === 'admitted' && <div className="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-900"><p>{t('guestMeeting.waiting.admittedNotice')}</p>{session.can_join_room && session.room_url && <button type="button" className="btn mt-4 w-full justify-center" onClick={() => router.visit(session.room_url)}>{t('guestMeeting.waiting.joinRoom')}</button>}</div>}
        {session.status === 'rejected' && session.terminal_url && <a className="btn-secondary mt-6 w-full justify-center" href={session.terminal_url}>{t('guestMeeting.waiting.exit')}</a>}
    </GuestShell>;
}

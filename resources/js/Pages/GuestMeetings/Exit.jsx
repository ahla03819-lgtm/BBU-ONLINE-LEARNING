import React from 'react';
import {Head} from '@inertiajs/react';
import {useTranslation} from '../../i18n/LocaleProvider';
import {GuestShell} from './Join';

/** Keeps a shared authenticated BBU session intact after guest cleanup. */
export default function GuestMeetingExit({authenticated, login_url: loginUrl, dashboard_url: dashboardUrl}) {
    const {t} = useTranslation();

    return <GuestShell title={t('guestMeeting.exit.title')}>
        <Head title={t('guestMeeting.exit.title')}/>
        <p className="text-sm font-semibold uppercase tracking-[.18em] text-indigo-700">{t('guestMeeting.brand')}</p>
        <h1 className="mt-3 text-3xl font-bold tracking-tight text-slate-900">{t('guestMeeting.exit.title')}</h1>
        <p className="mt-4 text-sm leading-6 text-slate-600">{t(authenticated ? 'guestMeeting.exit.authenticatedDescription' : 'guestMeeting.exit.description')}</p>
        <a className="btn mt-7 w-full justify-center" href={authenticated ? dashboardUrl : loginUrl}>{t(authenticated ? 'guestMeeting.exit.dashboard' : 'guestMeeting.exit.login')}</a>
    </GuestShell>;
}

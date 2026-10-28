import React from 'react';
import {Head, useForm} from '@inertiajs/react';
import {useTranslation} from '../../i18n/LocaleProvider';

export default function Join({invitation}) {
    const {t} = useTranslation();
    const form = useForm({display_name: ''});

    if (!invitation.available) {
        return <GuestShell title={t('guestMeeting.join.unavailableTitle')}><Unavailable t={t}/></GuestShell>;
    }

    const submit = (event) => {
        event.preventDefault();
        form.post(invitation.request_url, {preserveScroll: true});
    };

    return <GuestShell title={t('guestMeeting.join.title')}>
        <p className="text-sm font-semibold uppercase tracking-[.18em] text-indigo-700">{t('guestMeeting.brand')}</p>
        <h1 className="mt-3 text-3xl font-bold tracking-tight text-slate-900">{t('guestMeeting.join.title')}</h1>
        <p className="mt-3 text-sm leading-6 text-slate-600">{t('guestMeeting.join.description')}</p>
        {invitation.meeting_title && <div className="mt-6 rounded-2xl border border-indigo-100 bg-indigo-50 px-4 py-3"><p className="text-xs font-semibold uppercase tracking-wide text-indigo-700">{t('guestMeeting.join.meetingLabel')}</p><p className="mt-1 font-semibold text-slate-900">{invitation.meeting_title}</p></div>}
        <form className="mt-7 space-y-5" onSubmit={submit} noValidate>
            <label className="block text-sm font-semibold text-slate-800" htmlFor="guest-display-name">{t('guestMeeting.join.displayName')}</label>
            <input id="guest-display-name" className="input mt-2 w-full" autoComplete="name" maxLength="80" value={form.data.display_name} onChange={(event) => form.setData('display_name', event.target.value)} aria-describedby={form.errors.display_name ? 'guest-display-name-error' : undefined} aria-invalid={Boolean(form.errors.display_name)} required/>
            {form.errors.display_name && <p id="guest-display-name-error" className="text-sm text-red-700" role="alert">{form.errors.display_name}</p>}
            <button className="btn w-full justify-center py-3" disabled={form.processing}>{form.processing ? t('guestMeeting.join.requesting') : t('guestMeeting.join.request')}</button>
        </form>
        <p className="mt-6 text-xs leading-5 text-slate-500">{t('guestMeeting.join.privacyNotice')}</p>
    </GuestShell>;
}

export function GuestShell({title, children}) {
    return <><Head title={title}/><main className="min-h-screen bg-[radial-gradient(circle_at_top_left,_#e0e7ff,_transparent_36%),linear-gradient(135deg,#f8fafc,#eef2ff)] px-4 py-8 sm:px-6 sm:py-14"><section className="mx-auto w-full max-w-lg rounded-3xl border border-white/80 bg-white/95 p-6 shadow-[0_20px_55px_rgba(49,46,129,.16)] sm:p-9">{children}</section></main></>;
}

function Unavailable({t}) {
    return <><p className="text-sm font-semibold uppercase tracking-[.18em] text-indigo-700">{t('guestMeeting.brand')}</p><h1 className="mt-3 text-3xl font-bold text-slate-900">{t('guestMeeting.join.unavailableTitle')}</h1><p className="mt-4 text-sm leading-6 text-slate-600">{t('guestMeeting.join.unavailableDescription')}</p></>;
}

import React, {useState} from 'react';
import {Head, Link, router} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import SectionCard from '../../Components/UI/SectionCard';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import MeetingSchedule from '../../Components/Meetings/MeetingSchedule';
import {useTranslation} from '../../i18n/LocaleProvider';

const actionButton = 'inline-flex h-11 shrink-0 select-none items-center justify-center gap-2 whitespace-nowrap rounded-xl border px-4 text-sm font-semibold leading-none transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60';
const actionPrimary = `${actionButton} border-transparent bg-[var(--bbu-primary)] text-white shadow-sm hover:bg-[var(--bbu-primary-strong)] focus:ring-[var(--bbu-primary)]`;
const actionSecondary = `${actionButton} border-[#d8e5f2] bg-white text-slate-700 shadow-sm hover:border-[#bcd6ec] hover:bg-[var(--bbu-soft-navy)] hover:text-slate-900 focus:ring-[var(--bbu-primary)]`;
const actionDestructive = `${actionButton} border-[#f2c0ca] bg-[var(--bbu-soft-red)] text-[var(--bbu-red)] hover:border-[var(--bbu-red)] hover:bg-[#ffe1e6] focus:ring-[var(--bbu-red)]`;
const actionDestructiveStrong = `${actionButton} border-transparent bg-[var(--bbu-red)] text-white shadow-sm hover:bg-[#a5132c] focus:ring-[var(--bbu-red)]`;
const actionIcon = 'h-[18px] w-[18px] shrink-0';

export default function Show({schoolClass, meeting}) {
    const {t} = useTranslation();
    const [ending, setEnding] = useState(false);
    const [reconciling, setReconciling] = useState(false);
    const base = `/school-classes/${schoolClass.id}/meetings/${meeting.uuid}`;
    const cancel = () => { if (confirm(t('meetings.show.confirmCancel'))) router.patch(`${base}/cancel`); };
    const start = () => router.post(`${base}/start`);
    const end = () => {
        if (!ending && confirm(t('meetings.show.confirmEnd'))) {
            setEnding(true);
            router.post(`${base}/end`, {}, {onFinish: () => setEnding(false)});
        }
    };
    const reconcile = () => {
        if (reconciling) return;
        setReconciling(true);
        router.post(`${base}/reconcile`, {lifecycle_version: meeting.lifecycle_version}, {onFinish: () => setReconciling(false)});
    };

    return <Layout>
        <Head title={meeting.title}/>
        <div className="mx-auto max-w-6xl space-y-6">
            <MeetingPageHeader schoolClass={schoolClass} meeting={meeting} title={meeting.title} description={meeting.description || t('meetings.show.description')}/>
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,.75fr)]">
                <SectionCard className="p-6" title={t('meetings.show.detailsTitle')} description={t('meetings.show.detailsDescription')}>
                    <div className="mt-5 rounded-2xl border border-indigo-100 bg-[linear-gradient(135deg,#f4f2ff,#ffffff)] p-5"><MeetingSchedule start={meeting.scheduled_start_at} end={meeting.scheduled_end_at}/><div className="mt-4 flex items-center gap-3 text-sm text-slate-600"><span className="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-indigo-600 shadow-sm"><Icon name="users" className="h-4 w-4"/></span>{t('meetings.show.maximumParticipants')} <strong className="text-slate-800">{meeting.max_participants}</strong></div></div>
                    {meeting.description && <p className="mt-5 whitespace-pre-wrap text-sm leading-6 text-slate-600">{meeting.description}</p>}
                </SectionCard>
                <SectionCard className="p-6" title={t('meetings.show.classContextTitle')} description={t('meetings.show.classContextDescription')}>
                    <div className="mt-5 space-y-4 text-sm"><div className="flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600"><Icon name="school" className="h-5 w-5"/></span><div><p className="font-semibold text-slate-800">{schoolClass.name}</p><p className="text-slate-500">{schoolClass.section || t('meetings.show.classWorkspace')}</p></div></div><div className="flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600"><Icon name="book-open" className="h-5 w-5"/></span><div><p className="font-semibold text-slate-800">{meeting.class_subject?.name || t('meetings.show.generalMeeting')}</p><p className="text-slate-500">{t('meetings.show.learningContext')}</p></div></div><div className="flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600"><Icon name="user" className="h-5 w-5"/></span><div><p className="font-semibold text-slate-800">{meeting.host?.name || t('common.unassigned')}</p><p className="text-slate-500">{t('meetings.show.meetingHost')}</p></div></div></div>
                </SectionCard>
            </div>
            {['starting', 'ending'].includes(meeting.status) && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 shadow-sm"><div className="flex gap-3"><Icon name="clock" className="mt-0.5 h-5 w-5 shrink-0 text-amber-600"/><div><p className="font-semibold">{t(`meetings.show.${meeting.status === 'starting' ? 'preparingRoom' : 'finishingLiveClass'}`)}</p><p className="mt-1 text-amber-800">{t(`meetings.show.${meeting.status === 'starting' ? 'preparingRoomHint' : 'endingHint'}`)}</p>{meeting.can_reconcile && <button className="btn-secondary mt-4" disabled={reconciling} onClick={reconcile}>{reconciling ? t('meetings.show.checking') : t('meetings.show.retryReconciliation')}</button>}</div></div></div>}
            <SectionCard className="w-fit p-5" title={t('meetings.show.actionsTitle')} description={t('meetings.show.actionsDescription')}>
                <div className="flex flex-wrap items-center gap-3">
                    {meeting.can_update && <Link className={actionSecondary} href={`${base}/edit`}><Icon name="edit" className={actionIcon}/>{t('common.edit')}</Link>}
                    {meeting.can_start && meeting.status === 'scheduled' && <button className={actionPrimary} onClick={start}><Icon name="video" className={actionIcon}/>{t('meetings.show.startMeeting')}</button>}
                    {meeting.can_join && <Link className={actionPrimary} href={`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/lobby`}><Icon name="video" className={actionIcon}/>{t('meetings.show.joinMeeting')}</Link>}
                    {meeting.can_view_attendance && <Link className={actionSecondary} href={`${base}/attendance`}><Icon name="clipboard" className={actionIcon}/>{t('meetings.show.attendance')}</Link>}
                    {meeting.can_end && meeting.status === 'active' && <button className={actionDestructiveStrong} disabled={ending} onClick={end}><Icon name="power" className={actionIcon}/>{ending ? t('meetings.show.ending') : t('meetings.show.endMeeting')}</button>}
                    {meeting.can_cancel && meeting.status === 'scheduled' && <button className={actionDestructive} onClick={cancel}><Icon name="x" className={actionIcon}/>{t('meetings.show.cancelMeeting')}</button>}
                </div>
            </SectionCard>
        </div>
    </Layout>;
}

import React, {useState} from 'react';
import {Head, Link, router} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import SectionCard from '../../Components/UI/SectionCard';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import MeetingSchedule from '../../Components/Meetings/MeetingSchedule';

const actionButton = 'inline-flex h-11 shrink-0 select-none items-center justify-center gap-2 whitespace-nowrap rounded-xl border px-4 text-sm font-semibold leading-none transition focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60';
const actionPrimary = `${actionButton} border-transparent bg-[var(--bbu-primary)] text-white shadow-sm hover:bg-[var(--bbu-primary-strong)] focus:ring-[var(--bbu-primary)]`;
const actionSecondary = `${actionButton} border-[#d8e5f2] bg-white text-slate-700 shadow-sm hover:border-[#bcd6ec] hover:bg-[var(--bbu-soft-navy)] hover:text-slate-900 focus:ring-[var(--bbu-primary)]`;
const actionDestructive = `${actionButton} border-[#f2c0ca] bg-[var(--bbu-soft-red)] text-[var(--bbu-red)] hover:border-[var(--bbu-red)] hover:bg-[#ffe1e6] focus:ring-[var(--bbu-red)]`;
const actionDestructiveStrong = `${actionButton} border-transparent bg-[var(--bbu-red)] text-white shadow-sm hover:bg-[#a5132c] focus:ring-[var(--bbu-red)]`;
const actionIcon = 'h-[18px] w-[18px] shrink-0';

export default function Show({schoolClass, meeting}) {
    const [ending, setEnding] = useState(false);
    const [reconciling, setReconciling] = useState(false);
    const base = `/school-classes/${schoolClass.id}/meetings/${meeting.uuid}`;
    const cancel = () => { if (confirm('Cancel this scheduled meeting?')) router.patch(`${base}/cancel`); };
    const start = () => router.post(`${base}/start`);
    const end = () => {
        if (!ending && confirm('End this active meeting?')) {
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
            <MeetingPageHeader schoolClass={schoolClass} meeting={meeting} title={meeting.title} description={meeting.description || 'Review the live-class schedule and join when the room is available.'}/>
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(18rem,.75fr)]">
                <SectionCard className="p-6" title="Meeting details" description="Everything your class needs before the live session.">
                    <div className="mt-5 rounded-2xl border border-indigo-100 bg-[linear-gradient(135deg,#f4f2ff,#ffffff)] p-5"><MeetingSchedule start={meeting.scheduled_start_at} end={meeting.scheduled_end_at}/><div className="mt-4 flex items-center gap-3 text-sm text-slate-600"><span className="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-indigo-600 shadow-sm"><Icon name="users" className="h-4 w-4"/></span>Maximum participants: <strong className="text-slate-800">{meeting.max_participants}</strong></div></div>
                    {meeting.description && <p className="mt-5 whitespace-pre-wrap text-sm leading-6 text-slate-600">{meeting.description}</p>}
                </SectionCard>
                <SectionCard className="p-6" title="Class context" description="The authorized learning space for this session.">
                    <div className="mt-5 space-y-4 text-sm"><div className="flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet-50 text-violet-600"><Icon name="school" className="h-5 w-5"/></span><div><p className="font-semibold text-slate-800">{schoolClass.name}</p><p className="text-slate-500">{schoolClass.section || 'Class workspace'}</p></div></div><div className="flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-50 text-sky-600"><Icon name="book-open" className="h-5 w-5"/></span><div><p className="font-semibold text-slate-800">{meeting.class_subject?.name || 'General class meeting'}</p><p className="text-slate-500">Learning context</p></div></div><div className="flex items-center gap-3"><span className="flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 text-amber-600"><Icon name="user" className="h-5 w-5"/></span><div><p className="font-semibold text-slate-800">{meeting.host?.name || 'Unassigned'}</p><p className="text-slate-500">Meeting host</p></div></div></div>
                </SectionCard>
            </div>
            {['starting', 'ending'].includes(meeting.status) && <div className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900 shadow-sm"><div className="flex gap-3"><Icon name="clock" className="mt-0.5 h-5 w-5 shrink-0 text-amber-600"/><div><p className="font-semibold">{meeting.status === 'starting' ? 'Preparing the meeting room' : 'Finishing the live class'}</p><p className="mt-1 text-amber-800">{meeting.status === 'starting' ? 'The meeting room is being prepared.' : 'The meeting is ending and connected clients will return to the lobby.'}</p>{meeting.can_reconcile && <button className="btn-secondary mt-4" disabled={reconciling} onClick={reconcile}>{reconciling ? 'Checking…' : 'Retry reconciliation'}</button>}</div></div></div>}
            <SectionCard className="w-fit p-5" title="Available actions" description="Actions appear only when your current access permits them.">
                <div className="flex flex-wrap items-center gap-3">
                    {meeting.can_update && <Link className={actionSecondary} href={`${base}/edit`}><Icon name="edit" className={actionIcon}/>Edit</Link>}
                    {meeting.can_start && meeting.status === 'scheduled' && <button className={actionPrimary} onClick={start}><Icon name="video" className={actionIcon}/>Start meeting</button>}
                    {meeting.can_join && <Link className={actionPrimary} href={`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/lobby`}><Icon name="video" className={actionIcon}/>Join meeting</Link>}
                    {meeting.can_view_attendance && <Link className={actionSecondary} href={`${base}/attendance`}><Icon name="clipboard" className={actionIcon}/>Attendance</Link>}
                    {meeting.can_end && meeting.status === 'active' && <button className={actionDestructiveStrong} disabled={ending} onClick={end}><Icon name="power" className={actionIcon}/>{ending ? 'Ending…' : 'End meeting'}</button>}
                    {meeting.can_cancel && meeting.status === 'scheduled' && <button className={actionDestructive} onClick={cancel}><Icon name="x" className={actionIcon}/>Cancel meeting</button>}
                </div>
            </SectionCard>
        </div>
    </Layout>;
}

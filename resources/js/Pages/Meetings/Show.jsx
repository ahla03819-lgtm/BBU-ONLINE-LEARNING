import React, {useState} from 'react';
import {Head, Link, router} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import SectionCard from '../../Components/UI/SectionCard';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import MeetingSchedule from '../../Components/Meetings/MeetingSchedule';

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
            <SectionCard className="p-5" title="Available actions" description="Actions appear only when your current access permits them."><div className="mt-4 flex flex-wrap gap-3">{meeting.can_update && <Link className="btn-secondary" href={`${base}/edit`}><Icon name="edit" className="h-4 w-4"/>Edit</Link>}{meeting.can_start && meeting.status === 'scheduled' && <button className="btn" onClick={start}><Icon name="video" className="h-4 w-4"/>Start meeting</button>}{meeting.can_join && <Link className="btn" href={`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/lobby`}><Icon name="video" className="h-4 w-4"/>Join meeting</Link>}{meeting.can_end && meeting.status === 'active' && <button disabled={ending} className="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-50" onClick={end}><Icon name="power" className="h-4 w-4"/>{ending ? 'Ending…' : 'End meeting'}</button>}{meeting.can_cancel && meeting.status === 'scheduled' && <button className="inline-flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700 transition hover:bg-red-100" onClick={cancel}><Icon name="x" className="h-4 w-4"/>Cancel meeting</button>}</div></SectionCard>
        </div>
    </Layout>;
}

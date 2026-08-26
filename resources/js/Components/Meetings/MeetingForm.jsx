import React, {useMemo} from 'react';
import {useForm} from '@inertiajs/react';

const localDateTime = value => value ? new Date(value).toISOString().slice(0, 16) : '';

export default function MeetingForm({schoolClass, subjects, hostOptions, isAdministrator, canCreateGeneral, capacity, meeting}) {
    const editing = Boolean(meeting);
    const {data, setData, post, patch, processing, errors} = useForm({
        title: meeting?.title || '', description: meeting?.description || '', class_subject_id: meeting?.class_subject_id || '', host_user_id: meeting?.host_user_id || '',
        scheduled_start_at: localDateTime(meeting?.scheduled_start_at), scheduled_end_at: localDateTime(meeting?.scheduled_end_at), max_participants: meeting?.max_participants || capacity.default,
    });
    const hosts = useMemo(() => hostOptions[String(data.class_subject_id || 'general')] || [], [hostOptions, data.class_subject_id]);
    const submit = event => { event.preventDefault(); const url = `/school-classes/${schoolClass.id}/meetings${editing ? `/${meeting.uuid}` : ''}`; editing ? patch(url) : post(url); };
    const fieldError = name => errors[name] && <p className="mt-1 text-sm text-red-600">{errors[name]}</p>;
    return <form className="card max-w-3xl space-y-5" onSubmit={submit}>
        <div><label className="mb-1 block text-sm font-medium">Title</label><input className="field" value={data.title} onChange={e=>setData('title',e.target.value)}/>{fieldError('title')}</div>
        <div><label className="mb-1 block text-sm font-medium">Description</label><textarea className="field" rows="4" value={data.description} onChange={e=>setData('description',e.target.value)}/>{fieldError('description')}</div>
        <div><label className="mb-1 block text-sm font-medium">Meeting scope</label><select className="field" value={data.class_subject_id} onChange={e=>{setData('class_subject_id',e.target.value); setData('host_user_id','');}}>{canCreateGeneral&&<option value="">General class meeting</option>}{!canCreateGeneral&&<option value="" disabled>Select a subject</option>}{subjects.map(subject=><option key={subject.id} value={subject.id}>{subject.name}</option>)}</select>{fieldError('class_subject_id')}</div>
        {isAdministrator&&<div><label className="mb-1 block text-sm font-medium">Host</label><select className="field" value={data.host_user_id} onChange={e=>setData('host_user_id',e.target.value)}><option value="">Select an eligible current teacher</option>{hosts.map(host=><option key={host.id} value={host.id}>{host.name}</option>)}</select>{fieldError('host_user_id')}</div>}
        <div className="grid gap-4 md:grid-cols-2"><div><label className="mb-1 block text-sm font-medium">Starts</label><input type="datetime-local" className="field" value={data.scheduled_start_at} onChange={e=>setData('scheduled_start_at',e.target.value)}/>{fieldError('scheduled_start_at')}</div><div><label className="mb-1 block text-sm font-medium">Ends (optional)</label><input type="datetime-local" className="field" value={data.scheduled_end_at} onChange={e=>setData('scheduled_end_at',e.target.value)}/>{fieldError('scheduled_end_at')}</div></div>
        <div><label className="mb-1 block text-sm font-medium">Maximum participants</label><input type="number" min={capacity.min} max={capacity.max} className="field" value={data.max_participants} onChange={e=>setData('max_participants',e.target.value)}/><p className="mt-1 text-xs text-slate-500">Application scheduling range: {capacity.min}–{capacity.max}. This is not a provider infrastructure limit.</p>{fieldError('max_participants')}</div>
        {fieldError('meeting')}<button className="btn" disabled={processing}>{editing?'Save meeting':'Schedule meeting'}</button>
    </form>;
}

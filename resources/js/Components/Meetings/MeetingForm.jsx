import React, {useMemo} from 'react';
import {useForm, usePage} from '@inertiajs/react';
import Icon from '../UI/Icon';
import SectionCard from '../UI/SectionCard';
import {useTranslation} from '../../i18n/LocaleProvider';
import {utcInstantToAcademicDateTimeLocal} from './meetingDateTime';

export default function MeetingForm({schoolClass, subjects, hostOptions, isAdministrator, canCreateGeneral, capacity, meeting}) {
    const editing = Boolean(meeting);
    const {t} = useTranslation();
    const timezone = usePage().props.calendar?.timezone;
    const {data, setData, post, patch, processing, errors} = useForm({
        title: meeting?.title || '', description: meeting?.description || '', class_subject_id: meeting?.class_subject_id || '', host_user_id: meeting?.host_user_id || '',
        scheduled_start_at: utcInstantToAcademicDateTimeLocal(meeting?.scheduled_start_at, timezone), scheduled_end_at: utcInstantToAcademicDateTimeLocal(meeting?.scheduled_end_at, timezone), max_participants: meeting?.max_participants || capacity.default,
    });
    const hosts = useMemo(() => hostOptions[String(data.class_subject_id || 'general')] || [], [hostOptions, data.class_subject_id]);
    const submit = event => { event.preventDefault(); const url = `/school-classes/${schoolClass.id}/meetings${editing ? `/${meeting.uuid}` : ''}`; editing ? patch(url) : post(url); };
    const fieldError = name => errors[name] && <p className="mt-1 text-sm text-red-600">{errors[name]}</p>;
    return <form className="max-w-4xl space-y-6" onSubmit={submit}><SectionCard icon="video" tone="lavender" title={t('meetings.form.detailsTitle')} description={t('meetings.form.detailsDescription')}><div className="grid gap-5"><Field label={t('meetings.form.titleLabel')}><input className="field" value={data.title} onChange={e=>setData('title',e.target.value)}/>{fieldError('title')}</Field><Field label={t('meetings.form.descriptionLabel')}><textarea className="field min-h-32" rows="4" value={data.description} onChange={e=>setData('description',e.target.value)}/>{fieldError('description')}</Field><Field label={t('meetings.form.scopeLabel')}><select className="field" value={data.class_subject_id} onChange={e=>{setData('class_subject_id',e.target.value); setData('host_user_id','');}}>{canCreateGeneral&&<option value="">{t('meetings.form.generalMeeting')}</option>}{!canCreateGeneral&&<option value="" disabled>{t('meetings.form.selectSubject')}</option>}{subjects.map(subject=><option key={subject.id} value={subject.id}>{subject.name}</option>)}</select>{fieldError('class_subject_id')}</Field>{isAdministrator&&<Field label={t('meetings.form.hostLabel')}><select className="field" value={data.host_user_id} onChange={e=>setData('host_user_id',e.target.value)}><option value="">{t('meetings.form.selectHost')}</option>{hosts.map(host=><option key={host.id} value={host.id}>{host.name}</option>)}</select>{fieldError('host_user_id')}</Field>}</div></SectionCard><SectionCard icon="calendar" tone="amber" title={t('meetings.form.scheduleTitle')} description={t('meetings.form.scheduleDescription')}><div className="grid gap-5 md:grid-cols-2"><Field label={t('meetings.form.startsLabel')}><input type="datetime-local" className="field" value={data.scheduled_start_at} onChange={e=>setData('scheduled_start_at',e.target.value)}/>{fieldError('scheduled_start_at')}</Field><Field label={t('meetings.form.endsLabel')}><input type="datetime-local" className="field" value={data.scheduled_end_at} onChange={e=>setData('scheduled_end_at',e.target.value)}/>{fieldError('scheduled_end_at')}</Field></div><Field label={t('meetings.form.capacityLabel')}><input type="number" min={capacity.min} max={capacity.max} className="field" value={data.max_participants} onChange={e=>setData('max_participants',e.target.value)}/><p className="mt-1 text-xs leading-5 text-slate-500">{t('meetings.form.capacityNote', {min: capacity.min, max: capacity.max})}</p>{fieldError('max_participants')}</Field></SectionCard>{fieldError('meeting')}<button className="btn" disabled={processing}><Icon name="calendar" className="mr-2 h-4 w-4"/>{editing ? t('meetings.form.saveMeeting') : t('meetings.schedule')}</button></form>;
}

function Field({label, children}) { return <label className="block text-sm font-semibold text-slate-700"><span className="mb-1.5 block">{label}</span>{children}</label>; }

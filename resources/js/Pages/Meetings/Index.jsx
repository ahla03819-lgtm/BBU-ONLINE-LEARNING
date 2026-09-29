import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingCard from '../../Components/Meetings/MeetingCard';
import MeetingEmptyState from '../../Components/Meetings/MeetingEmptyState';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import Icon from '../../Components/UI/Icon';
import {useTranslation} from '../../i18n/LocaleProvider';

// Group keys stay stable so the grouping logic never depends on the display language.
const groups = [
    {key: 'liveNow', statuses: ['active']},
    {key: 'scheduled', statuses: ['scheduled']},
    {key: 'transitioning', statuses: ['starting', 'ending']},
    {key: 'history', statuses: ['ended', 'cancelled']},
];

export default function Index({schoolClass, meetings, canCreate}) {
    const {t} = useTranslation();

    return <Layout><Head title={`${schoolClass.name} ${t('nav.items.meetings')}`}/><MeetingPageHeader schoolClass={schoolClass} eyebrow={t('meetings.index.eyebrow')} title={t('meetings.title')} description={t('meetings.index.description')} backHref={`/collaboration/classes/${schoolClass.id}`} backLabel={t('meetings.index.backLabel')} action={canCreate && <Link className="btn" href={`/school-classes/${schoolClass.id}/meetings/create`}><Icon name="calendar" className="mr-2 h-4 w-4"/>{t('meetings.schedule')}</Link>}/>{groups.map((group) => { const items = meetings.filter((meeting) => group.statuses.includes(meeting.status)); return items.length > 0 && <section className="mt-6" key={group.key}><div className="mb-3 flex items-center gap-2"><span className={`inline-flex h-8 w-8 items-center justify-center rounded-lg ${group.key === 'liveNow' ? 'edway-tone-mint text-emerald-700' : group.key === 'history' ? 'bg-slate-100 text-slate-600' : 'edway-tone-lavender text-indigo-700'}`}><Icon name={group.key === 'liveNow' ? 'video' : 'calendar'} className="h-4 w-4"/></span><div><h2 className="font-bold text-slate-900">{t(`meetings.groups.${group.key}`)}</h2><p className="text-sm text-slate-500">{t(`meetings.groupDescriptions.${group.key}`)}</p></div></div><div className="grid gap-4 md:grid-cols-2">{items.map((meeting) => <MeetingCard key={meeting.uuid} schoolClass={schoolClass} meeting={meeting}/>)}</div></section>; })}{meetings.length === 0 && <div className="mt-6"><MeetingEmptyState title={t('meetings.noMeetings')} action={canCreate && <Link className="btn" href={`/school-classes/${schoolClass.id}/meetings/create`}><Icon name="calendar" className="mr-2 h-4 w-4"/>{t('meetings.schedule')}</Link>}/></div>}</Layout>;
}

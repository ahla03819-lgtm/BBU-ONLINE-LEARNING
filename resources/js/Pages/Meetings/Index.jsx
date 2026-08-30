import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingCard from '../../Components/Meetings/MeetingCard';
import MeetingEmptyState from '../../Components/Meetings/MeetingEmptyState';
import MeetingPageHeader from '../../Components/Meetings/MeetingPageHeader';
import Icon from '../../Components/UI/Icon';

const groups = [
    ['Live now', ['active']],
    ['Scheduled', ['scheduled']],
    ['Transitioning', ['starting', 'ending']],
    ['History', ['ended', 'cancelled']],
];

export default function Index({schoolClass, meetings, canCreate}) { return <Layout><Head title={`${schoolClass.name} meetings`}/><MeetingPageHeader schoolClass={schoolClass} eyebrow="Live classes" title="Meetings" description="Schedule, join, and manage only the live classes authorized for this class." backHref={`/collaboration/classes/${schoolClass.id}`} backLabel="Class workspace" action={canCreate && <Link className="btn" href={`/school-classes/${schoolClass.id}/meetings/create`}><Icon name="calendar" className="mr-2 h-4 w-4"/>Schedule meeting</Link>}/>{groups.map(([label, statuses]) => { const items = meetings.filter((meeting) => statuses.includes(meeting.status)); return items.length > 0 && <section className="mt-6" key={label}><div className="mb-3 flex items-center gap-2"><span className={`inline-flex h-8 w-8 items-center justify-center rounded-lg ${label === 'Live now' ? 'edway-tone-mint text-emerald-700' : label === 'History' ? 'bg-slate-100 text-slate-600' : 'edway-tone-lavender text-indigo-700'}`}><Icon name={label === 'Live now' ? 'video' : 'calendar'} className="h-4 w-4"/></span><div><h2 className="font-bold text-slate-900">{label}</h2><p className="text-sm text-slate-500">{sectionDescription(label)}</p></div></div><div className="grid gap-4 md:grid-cols-2">{items.map((meeting) => <MeetingCard key={meeting.uuid} schoolClass={schoolClass} meeting={meeting}/>)}</div></section>; })}{meetings.length === 0 && <div className="mt-6"><MeetingEmptyState title="No meetings yet" action={canCreate && <Link className="btn" href={`/school-classes/${schoolClass.id}/meetings/create`}><Icon name="calendar" className="mr-2 h-4 w-4"/>Schedule meeting</Link>}/></div>}</Layout>; }

function sectionDescription(label) { return {"Live now": 'Meetings currently available to join.', Scheduled: 'Upcoming sessions for this class.', Transitioning: 'Rooms moving through the meeting lifecycle.', History: 'Completed or cancelled meeting records.'}[label]; }

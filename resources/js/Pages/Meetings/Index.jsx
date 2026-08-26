import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingCard from '../../Components/Meetings/MeetingCard';

const groups = [
    ['Live now', ['active']],
    ['Scheduled', ['scheduled']],
    ['Transitioning', ['starting', 'ending']],
    ['History', ['ended', 'cancelled']],
];

export default function Index({schoolClass, meetings, canCreate}) { return <Layout><Head title={`${schoolClass.name} meetings`}/><div className="mb-6 flex items-start justify-between"><div><Link className="text-sm text-indigo-600" href={`/collaboration/classes/${schoolClass.id}`}>← Class workspace</Link><h1 className="mt-2 text-2xl font-bold">Meetings · {schoolClass.name} {schoolClass.section}</h1><p className="text-slate-500">{schoolClass.grade_level.name} · {schoolClass.academic_year.name}</p></div>{canCreate&&<Link className="btn" href={`/school-classes/${schoolClass.id}/meetings/create`}>Schedule meeting</Link>}</div>{groups.map(([label,statuses])=>{const items=meetings.filter(meeting=>statuses.includes(meeting.status));return items.length>0&&<section className="mb-8" key={label}><h2 className="mb-3 text-lg font-semibold">{label}</h2><div className="grid gap-4">{items.map(meeting=><MeetingCard key={meeting.uuid} schoolClass={schoolClass} meeting={meeting}/>)}</div></section>;})}{meetings.length===0&&<div className="card text-slate-500"><h2 className="font-medium text-slate-700">No meetings yet</h2><p className="mt-1">Scheduled and live meetings for this class will appear here.</p></div>}</Layout>; }

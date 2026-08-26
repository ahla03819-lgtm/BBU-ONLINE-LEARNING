import React from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import MeetingCard from '../../Components/Meetings/MeetingCard';

export default function Index({schoolClass, meetings, canCreate}) { return <Layout><Head title={`${schoolClass.name} meetings`}/><div className="mb-6 flex items-start justify-between"><div><Link className="text-sm text-indigo-600" href={`/collaboration/classes/${schoolClass.id}`}>← Class workspace</Link><h1 className="mt-2 text-2xl font-bold">Meetings · {schoolClass.name} {schoolClass.section}</h1><p className="text-slate-500">{schoolClass.grade_level.name} · {schoolClass.academic_year.name}</p></div>{canCreate&&<Link className="btn" href={`/school-classes/${schoolClass.id}/meetings/create`}>Schedule meeting</Link>}</div><div className="grid gap-4">{meetings.map(meeting=><MeetingCard key={meeting.uuid} schoolClass={schoolClass} meeting={meeting}/>)}</div>{meetings.length===0&&<div className="card text-slate-500">No meetings are available for this class.</div>}</Layout>; }

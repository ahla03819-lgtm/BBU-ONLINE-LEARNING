import React from 'react';
import {Link} from '@inertiajs/react';
import MeetingSchedule from './MeetingSchedule';
import MeetingStatusBadge from './MeetingStatusBadge';

export default function MeetingCard({schoolClass, meeting}) {
    return <article className="card"><div className="flex items-start justify-between gap-4"><div><h2 className="font-semibold"><Link className="hover:text-indigo-600" href={`/school-classes/${schoolClass.id}/meetings/${meeting.uuid}`}>{meeting.title}</Link></h2><p className="text-sm text-slate-500">{meeting.class_subject?.name || 'General class meeting'} · Host: {meeting.host?.name || 'Unassigned'}</p></div><MeetingStatusBadge status={meeting.status}/></div><div className="mt-4"><MeetingSchedule start={meeting.scheduled_start_at} end={meeting.scheduled_end_at}/></div></article>;
}

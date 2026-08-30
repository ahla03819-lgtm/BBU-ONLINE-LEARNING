import React from 'react';
import Icon from '../UI/Icon';

export default function MeetingSchedule({start, end}) {
    const format = value => value ? new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(value)) : 'Not set';
    return <div className="space-y-1 text-sm text-slate-600"><div className="flex items-center gap-1.5"><Icon name="calendar" className="h-4 w-4 text-indigo-500"/>{format(start)}</div>{end && <div className="pl-5 text-xs text-slate-500">Ends {format(end)}</div>}</div>;
}

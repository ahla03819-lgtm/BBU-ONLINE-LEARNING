import React from 'react';

export default function MeetingSchedule({start, end}) {
    const format = value => value ? new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(value)) : 'Not set';
    return <div className="text-sm text-slate-600"><div>{format(start)}</div>{end && <div>Ends {format(end)}</div>}</div>;
}

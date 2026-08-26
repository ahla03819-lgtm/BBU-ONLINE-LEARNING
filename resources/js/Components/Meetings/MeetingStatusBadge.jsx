import React from 'react';

const styles = {scheduled: 'bg-blue-100 text-blue-800', cancelled: 'bg-slate-200 text-slate-700'};

export default function MeetingStatusBadge({status}) {
    return <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${styles[status] || 'bg-amber-100 text-amber-800'}`}>{status.replace('_', ' ')}</span>;
}

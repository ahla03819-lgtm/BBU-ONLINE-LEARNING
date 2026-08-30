import React from 'react';

const styles = {scheduled: 'edway-tone-lavender text-indigo-800', starting: 'edway-tone-amber text-amber-800', active: 'edway-tone-mint text-emerald-800', ending: 'edway-tone-amber text-amber-800', ended: 'bg-slate-100 text-slate-700', cancelled: 'edway-tone-coral text-rose-800'};

export default function MeetingStatusBadge({status}) {
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-bold capitalize ${styles[status] || 'edway-tone-amber text-amber-800'}`}>{status.replace('_', ' ')}</span>;
}

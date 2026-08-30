import React from 'react';

export default function StatusBadge({value}) {
    const normalized = String(value || 'unavailable').toLowerCase();
    const palette = {active: 'edway-tone-mint text-emerald-800', open: 'edway-tone-mint text-emerald-800', published: 'edway-tone-mint text-emerald-800', present: 'edway-tone-mint text-emerald-800', submitted: 'edway-tone-mint text-emerald-800', graded: 'edway-tone-lavender text-indigo-800', scheduled: 'edway-tone-lavender text-indigo-800', draft: 'edway-tone-lavender text-indigo-800', closed: 'bg-slate-100 text-slate-700', finalized: 'bg-slate-100 text-slate-700', late: 'edway-tone-amber text-amber-800', absent: 'edway-tone-coral text-rose-800', excused: 'edway-tone-blue text-sky-800'};
    return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold capitalize ${palette[normalized] || 'bg-slate-100 text-slate-700'}`}>{normalized.replaceAll('-', ' ')}</span>;
}

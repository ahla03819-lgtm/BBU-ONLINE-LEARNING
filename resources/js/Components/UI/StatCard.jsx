import React from 'react';
import Icon from './Icon';

export default function StatCard({label, value, hint, tone = 'indigo', action}) {
    const tones = {indigo: 'edway-tone-lavender text-indigo-700', emerald: 'edway-tone-mint text-emerald-700', amber: 'edway-tone-amber text-amber-800', sky: 'edway-tone-blue text-sky-700', coral: 'edway-tone-coral text-rose-700'};
    const icons = {Students: 'users', Teachers: 'users', Classes: 'school', Subjects: 'book'};
    return <article className={`edway-card relative min-h-36 overflow-hidden border-white/80 p-4 shadow-[0_12px_28px_rgba(78,72,142,.09)] ${tones[tone] || tones.indigo}`}><span className="absolute -right-6 bottom-0 h-16 w-28 rounded-tl-[70%] border-t border-l border-white/45 bg-white/20"/><span className="absolute left-3 top-3 h-14 w-14 rounded-full bg-white/35 blur-md"/><div className="relative flex items-start justify-between gap-3"><span className="inline-flex h-12 w-12 items-center justify-center rounded-full border border-white/70 bg-white/85 shadow-md shadow-indigo-950/5"><Icon name={icons[label] || 'spark'} className="h-5 w-5"/></span>{action}</div><p className="relative mt-2.5 text-2xl font-bold tracking-tight text-slate-900">{value}</p><p className="relative mt-0.5 text-sm font-bold text-slate-700">{label}</p><p className="relative mt-0.5 text-xs text-slate-500">{hint}</p></article>;
}

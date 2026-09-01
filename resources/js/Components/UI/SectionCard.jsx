import React from 'react';
import Icon from './Icon';

export default function SectionCard({title, description, action, children, className = '', icon, tone = 'lavender'}) {
    const tones = {lavender: 'edway-tone-lavender text-sky-800', blue: 'edway-tone-blue text-sky-700', amber: 'edway-tone-amber text-amber-700', mint: 'edway-tone-mint text-emerald-700', coral: 'edway-tone-coral text-rose-700'};
    return <section className={`edway-card ${className}`}><div className="flex flex-wrap items-start justify-between gap-3"><div className="flex items-start gap-3">{icon && <span className={`inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${tones[tone] || tones.lavender}`}><Icon name={icon} className="h-5 w-5"/></span>}<div><h2 className="text-base font-bold text-slate-900">{title}</h2>{description && <p className="mt-1 text-sm leading-6 text-slate-500">{description}</p>}</div></div>{action}</div><div className="mt-5">{children}</div></section>;
}

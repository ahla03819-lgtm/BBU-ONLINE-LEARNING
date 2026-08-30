import React from 'react';
import Icon from '../UI/Icon';

export default function MeetingEmptyState({title = 'No meetings yet', text = 'Scheduled and live meetings for this class will appear here.', action}) {
    return <div className="edway-card flex min-h-64 flex-col items-center justify-center text-center"><span className="inline-flex h-16 w-16 items-center justify-center rounded-3xl edway-tone-lavender text-indigo-700 shadow-sm"><Icon name="video" className="h-8 w-8"/></span><h2 className="mt-4 font-bold text-slate-800">{title}</h2><p className="mt-1 max-w-md text-sm leading-6 text-slate-500">{text}</p>{action && <div className="mt-4">{action}</div>}</div>;
}

import React from 'react';

export default function TypingIndicator({names}) {
    if (!names.length) return <div className="h-5"/>;
    return <div className="h-5 text-xs italic text-slate-500">{names.slice(0, 2).join(' and ')} {names.length === 1 ? 'is' : 'are'} typing…</div>;
}

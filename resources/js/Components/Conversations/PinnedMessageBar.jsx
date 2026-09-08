import React from 'react';
import Icon from '../UI/Icon';

export default function PinnedMessageBar({message, onFocus}) {
    if (!message) return null;
    return <button type="button" onClick={() => onFocus(message.id)} className="flex w-full items-center gap-3 border-b border-blue-100 bg-blue-50/70 px-5 py-2.5 text-left hover:bg-blue-50"><span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-blue-800 shadow-sm"><Icon name="spark" className="h-4 w-4"/></span><span className="min-w-0"><span className="block text-xs font-bold uppercase tracking-wide text-blue-700">Pinned message</span><span className="block truncate text-sm text-slate-700">{message.deleted ? 'Message unavailable' : message.body || 'Attachment'}</span></span></button>;
}

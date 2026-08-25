import React from 'react';

export default function ReplyPreview({reply, onCancel}) {
    if (!reply) return null;
    return <div className="mb-2 flex items-start justify-between rounded-lg border-l-4 border-indigo-400 bg-indigo-50 p-2 text-xs">
        <div><strong>{reply.sender?.name || 'Former user'}</strong><div className="line-clamp-2 text-slate-600">{reply.hidden_at ? 'Message hidden' : reply.body}</div></div>
        {onCancel && <button type="button" onClick={onCancel} aria-label="Cancel reply">×</button>}
    </div>;
}

import React from 'react';
import ReplyPreview from './ReplyPreview';
import AttachmentList from './AttachmentList';
import ReactionSummary from './ReactionSummary';

export default function MessageItem({message, currentUser, canModerate, canReact, onReply, onEdit, onHide, onModerate, onRetry, onReact}) {
    const mine = String(message.sender?.id) === String(currentUser.id);
    const age = message.created_at ? Date.now() - new Date(message.created_at).getTime() : Infinity;
    const withinWindow = age <= 15 * 60 * 1000;
    return <article className={`rounded-lg p-3 ${mine ? 'bg-indigo-50' : 'bg-slate-50'} ${message.failed ? 'border border-red-300' : ''}`}>
        {message.reply_to && <ReplyPreview reply={message.reply_to}/>}
        <div className="flex justify-between gap-3"><strong className="text-sm">{message.sender?.name || 'System'}</strong><time className="text-xs text-slate-400">{message.created_at ? new Date(message.created_at).toLocaleString() : ''}</time></div>
        {message.hidden_at ? <p className="mt-1 italic text-slate-400">This message was hidden.</p> : <p className="mt-1 whitespace-pre-wrap break-words text-sm">{message.body}</p>}
        {!message.hidden_at && <AttachmentList attachments={message.attachments}/>}
        {message.uploadProgress !== undefined && <div className="mt-2 h-1 overflow-hidden rounded bg-slate-200"><div className="h-full bg-indigo-500" style={{width: `${message.uploadProgress}%`}}/></div>}
        <ReactionSummary message={message} canReact={canReact} onReact={onReact}/>
        <div className="mt-2 flex flex-wrap gap-3 text-xs text-slate-500">
            {message.edited_at && <span>Edited</span>}{message.pending && <span>Sending…</span>}{message.failed && <button onClick={() => onRetry(message)} className="text-red-600">Retry</button>}
            {!message.hidden_at && !message.pending && !message.failed && <button onClick={() => onReply(message)}>Reply</button>}
            {mine && withinWindow && !message.hidden_at && message.type !== 'system' && <button onClick={() => onEdit(message)}>Edit</button>}
            {mine && withinWindow && !message.hidden_at && message.type !== 'system' && <button onClick={() => onHide(message)}>Hide</button>}
            {canModerate && !message.hidden_at && !mine && <button className="text-red-600" onClick={() => onModerate(message)}>Moderate</button>}
        </div>
        {message.error && <p className="mt-1 text-xs text-red-600">{message.error}</p>}
    </article>;
}

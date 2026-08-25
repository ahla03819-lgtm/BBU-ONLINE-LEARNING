import React, {useState} from 'react';
import ReplyPreview from './ReplyPreview';

export default function MessageComposer({disabled, reply, onCancelReply, onSend, onTyping}) {
    const [body, setBody] = useState('');
    const submit = async event => { event.preventDefault(); if (!body.trim()) return; const value = body; setBody(''); onTyping(false); await onSend({body: value, replyTo: reply}); onCancelReply(); };
    return <form onSubmit={submit} className="mt-4 border-t pt-4">
        <ReplyPreview reply={reply} onCancel={onCancelReply}/>
        <textarea className="field min-h-24" value={body} maxLength={4000} disabled={disabled} placeholder="Write a plain-text message…" onChange={event => {setBody(event.target.value); onTyping(Boolean(event.target.value));}} onBlur={() => onTyping(false)}/>
        <div className="mt-2 flex items-center justify-between"><span className="text-xs text-slate-400">{body.length}/4000</span><button className="btn" disabled={disabled || !body.trim()}>Send</button></div>
    </form>;
}

import React, {useState} from 'react';
import ReplyPreview from './ReplyPreview';
import AttachmentPicker from './AttachmentPicker';

export default function MessageComposer({disabled, canUpload, reply, onCancelReply, onSend, onTyping}) {
    const [body, setBody] = useState('');
    const [attachments, setAttachments] = useState([]);
    const [error, setError] = useState(null);
    const submit = async event => {
        event.preventDefault(); if (!body.trim() && !attachments.length) return;
        const total = attachments.reduce((sum, item) => sum + item.file.size, 0);
        if (attachments.some(item => item.file.size > 10 * 1024 * 1024) || total > 25 * 1024 * 1024) { setError('Attachment size limits exceeded.'); return; }
        const value = body; const files = attachments; setBody(''); setAttachments([]); setError(null); onTyping(false);
        await onSend({body: value, replyTo: reply, attachments: files}); onCancelReply();
    };
    return <form onSubmit={submit} className="mt-4 border-t pt-4">
        <ReplyPreview reply={reply} onCancel={onCancelReply}/>
        <textarea className="field min-h-24" value={body} maxLength={4000} disabled={disabled} placeholder="Write a plain-text message…" onChange={event => {setBody(event.target.value); onTyping(Boolean(event.target.value));}} onBlur={() => onTyping(false)}/>
        {canUpload && <AttachmentPicker attachments={attachments} setAttachments={setAttachments} error={error}/>}
        <div className="mt-2 flex items-center justify-between"><span className="text-xs text-slate-400">{body.length}/4000</span><button className="btn" disabled={disabled || (!body.trim() && !attachments.length)}>Send</button></div>
    </form>;
}

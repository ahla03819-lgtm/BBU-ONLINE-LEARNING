import React, {useEffect, useRef, useState} from 'react';
import {useParticipants} from '@livekit/components-react';
import Icon from '../../UI/Icon';
import MeetingParticipantAvatar from './MeetingParticipantAvatar';

function timestamp(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
}

export function MeetingSidePanel({title, icon, onClose, children}) {
    return <aside className="flex min-h-0 flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white text-slate-900 shadow-xl shadow-slate-900/10" aria-label={title}>
        <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4"><div className="flex items-center gap-3"><span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 text-sky-700"><Icon name={icon} className="h-4 w-4"/></span><h2 className="font-bold">{title}</h2></div><button type="button" onClick={onClose} className="rounded-xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-600" aria-label={`Close ${title}`}><Icon name="x" className="h-4 w-4"/></button></header>
        {children}
    </aside>;
}

export function MeetingChatPanel({messages, onClose, onSend, connected, maxMessageLength}) {
    const [body, setBody] = useState('');
    const [error, setError] = useState('');
    const endRef = useRef(null);
    useEffect(() => endRef.current?.scrollIntoView({block: 'end'}), [messages]);
    const send = async () => {
        if (!body.trim()) return;
        try { await onSend(body); setBody(''); setError(''); } catch (problem) { setError(problem.message || 'Unable to send this meeting message.'); }
    };
    const keyDown = (event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); send(); } };

    return <MeetingSidePanel title="Meeting chat" icon="messages" onClose={onClose}><div className="min-h-0 flex-1 overflow-y-auto p-4">{messages.length === 0 ? <div className="flex min-h-44 flex-col items-center justify-center px-5 text-center"><span className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-50 text-sky-700"><Icon name="messages" className="h-5 w-5"/></span><h3 className="mt-3 text-sm font-bold text-slate-900">Start the conversation</h3><p className="mt-1 text-xs leading-5 text-slate-500">Messages are available only to people currently in this live meeting.</p></div> : <div className="space-y-3">{messages.map((message) => <article key={message.id} className={`flex gap-2.5 ${message.local ? 'flex-row-reverse' : ''}`}><MeetingParticipantAvatar participant={message.sender} size="sm"/><div className={`max-w-[82%] rounded-2xl px-3 py-2 text-sm shadow-sm ${message.local ? 'bg-sky-700 text-white' : 'bg-slate-100 text-slate-800'}`}><p className={`mb-1 text-[11px] font-bold ${message.local ? 'text-sky-100' : 'text-slate-500'}`}>{message.local ? 'You' : message.sender.name}</p><p className="whitespace-pre-wrap break-words leading-5">{message.body}</p><p className={`mt-1 text-right text-[10px] ${message.local ? 'text-sky-100' : 'text-slate-400'}`}>{timestamp(message.sentAt)}</p></div></article>)}</div>}<div ref={endRef}/></div><form className="border-t border-slate-100 p-3" onSubmit={(event) => { event.preventDefault(); send(); }}><label className="sr-only" htmlFor="meeting-chat-message">Write a meeting message</label><textarea id="meeting-chat-message" rows="2" maxLength={maxMessageLength} value={body} onChange={(event) => setBody(event.target.value)} onKeyDown={keyDown} placeholder="Write a message…" disabled={!connected} className="field min-h-20 resize-none"/>{error && <p className="mt-2 text-xs font-medium text-rose-700" role="alert">{error}</p>}<div className="mt-2 flex items-center justify-between gap-3"><p className="text-[11px] text-slate-500">{body.length}/{maxMessageLength}</p><button type="submit" disabled={!connected || !body.trim()} className="inline-flex min-h-9 items-center gap-1.5 rounded-xl bg-sky-700 px-3 py-2 text-xs font-bold text-white transition hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50"><Icon name="arrow-right" className="h-3.5 w-3.5"/>Send</button></div></form></MeetingSidePanel>;
}

export function ParticipantsPanel({meeting, onClose, raisedHands, records, canManage, onRemove, removing}) {
    const participants = useParticipants();
    const recordsByName = new Map(records.filter((record) => !record.removed).map((record) => [record.display_name, record]));
    return <MeetingSidePanel title={`People (${participants.length})`} icon="users" onClose={onClose}><div className="min-h-0 flex-1 overflow-y-auto p-3">{participants.length === 0 ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">No participants are connected yet.</p> : <div className="space-y-2">{participants.map((participant) => {
        const record = recordsByName.get(participant.name);
        const isHost = participant.name && participant.name === meeting.host?.name;
        const raised = Boolean(raisedHands[participant.identity]);
        return <div key={participant.identity} className="flex items-center gap-3 rounded-2xl px-2 py-2.5 hover:bg-slate-50"><MeetingParticipantAvatar participant={participant} size="sm"/><div className="min-w-0 flex-1"><p className="truncate text-sm font-bold text-slate-900">{participant.name || 'Participant'}</p><p className={`mt-0.5 text-xs font-medium ${isHost ? 'text-violet-700' : 'text-slate-500'}`}>{isHost ? 'Organizer' : 'Participant'}</p></div>{raised && <span className="rounded-lg bg-amber-50 p-1.5 text-amber-700" title="Hand raised"><Icon name="hand" className="h-4 w-4"/></span>}<span className="flex items-center gap-1.5 text-slate-500"><Icon name={participant.isMicrophoneEnabled ? 'mic' : 'mic-off'} className={`h-4 w-4 ${participant.isMicrophoneEnabled ? 'text-emerald-600' : 'text-rose-500'}`}/><Icon name={participant.isCameraEnabled ? 'video' : 'video-off'} className={`h-4 w-4 ${participant.isCameraEnabled ? 'text-sky-600' : 'text-slate-400'}`}/></span>{canManage && record?.role !== 'host' && <button type="button" disabled={removing !== null} onClick={() => onRemove(record.reference)} className="rounded-lg border border-slate-200 p-1.5 text-slate-500 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 disabled:opacity-50" aria-label={`Remove ${participant.name || 'participant'}`}><Icon name={removing === record.reference ? 'loader' : 'x'} className={`h-4 w-4 ${removing === record.reference ? 'animate-spin' : ''}`}/></button>}</div>;
    })}</div>}</div></MeetingSidePanel>;
}

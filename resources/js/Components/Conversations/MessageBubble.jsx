import React, {useRef, useState} from 'react';
import Icon from '../UI/Icon';
import UserAvatar from '../UI/UserAvatar';
import {DocumentMessageBubble, isDocumentAttachment} from './DocumentAttachment';

const formatTime = timestamp => timestamp ? new Intl.DateTimeFormat(undefined, {hour: 'numeric', minute: '2-digit'}).format(new Date(timestamp)) : '';
const attachmentLabel = attachment => ({image: 'Photo', voice: 'Voice message', video: 'Video'}[attachment.type] || 'Document');
const isVoiceAttachment = attachment => {
    const mimeType = String(attachment.mime_type || '').toLowerCase();
    const name = String(attachment.name || '');

    return attachment.type === 'voice'
        || attachment.type === 'audio'
        || mimeType.startsWith('audio/')
        // Existing browser recordings were persisted as video/webm before the
        // storage classifier recognized the controlled voice-recorder filename.
        || (attachment.type === 'video' && mimeType === 'video/webm' && /^voice-message-\d+\.webm$/i.test(name));
};

export function VoiceMessagePlayer({attachment, isOutgoing, timestamp, read}) {
    const audio = useRef(null); const [playing, setPlaying] = useState(false); const [duration, setDuration] = useState(0); const [currentTime, setCurrentTime] = useState(0);
    const format = value => { const seconds = Number.isFinite(value) && value >= 0 ? Math.floor(value) : 0; return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`; };
    const syncDuration = () => setDuration(Number.isFinite(audio.current?.duration) ? audio.current.duration : 0);
    const toggle = async () => { if (!audio.current) return; if (audio.current.paused) { try { await audio.current.play(); } catch { setPlaying(false); } } else audio.current.pause(); };
    const seek = event => { if (!audio.current || !duration) return; const next = Math.max(0, Math.min(duration, Number(event.target.value))); audio.current.currentTime = next; setCurrentTime(next); };
    const tone = isOutgoing ? 'border-white/25 bg-white/10 text-white' : 'border-blue-100 bg-blue-50 text-blue-950';
    const waveform = [35, 62, 48, 82, 55, 74, 42, 64, 88, 50, 70, 38, 76, 57, 68, 45, 80, 52, 66, 40, 72, 58, 84, 46];
    const progress = duration ? Math.min(100, (currentTime / duration) * 100) : 0;
    return <div className={`mt-2 w-64 max-w-full rounded-2xl border px-2.5 py-2.5 ${tone}`}><audio ref={audio} src={attachment.url} preload="metadata" className="sr-only" onLoadedMetadata={syncDuration} onDurationChange={syncDuration} onTimeUpdate={() => setCurrentTime(Number.isFinite(audio.current?.currentTime) ? audio.current.currentTime : 0)} onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)} onEnded={() => { setPlaying(false); setCurrentTime(0); if (audio.current) audio.current.currentTime = 0; }}/><div className="flex items-center gap-2.5"><button type="button" onClick={toggle} className={`inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full shadow-sm ${isOutgoing ? 'bg-white text-blue-800' : 'bg-blue-800 text-white'}`} aria-label={playing ? 'Pause voice message' : 'Play voice message'}><Icon name={playing ? 'pause' : 'play'} className="h-4 w-4"/></button><label className="relative flex h-8 min-w-0 flex-1 cursor-pointer items-center" aria-label="Voice message progress"><span className="pointer-events-none flex h-5 w-full items-center gap-px overflow-hidden"><span className="absolute inset-y-0 left-0 rounded-full bg-current opacity-15" style={{width: `${progress}%`}}/>{waveform.map((height, index) => <span key={index} className="relative z-10 min-w-[2px] flex-1 rounded-full bg-current opacity-60" style={{height: `${height}%`}}/>)}</span><input type="range" min="0" max={duration || 0} step="0.1" value={Math.min(currentTime, duration || 0)} onChange={seek} disabled={!duration} aria-label="Seek voice message" className="absolute inset-0 h-full w-full cursor-pointer opacity-0 disabled:cursor-not-allowed"/></label></div><div className="mt-1.5 flex items-center justify-between gap-2 pl-[50px] text-[11px] tabular-nums opacity-75"><span>{format(playing ? currentTime : duration)}</span><span className="truncate">{timestamp}{isOutgoing && read ? ' · ✓✓' : isOutgoing ? ' · ✓' : ''}</span></div></div>;
}

function Attachments({attachments, isOutgoing, timestamp, read}) {
    return <>{attachments.map(attachment => attachment.type === 'image' ? <a key={attachment.uuid} href={attachment.url} target="_blank" rel="noreferrer"><img src={attachment.url} alt={attachment.name} className="mt-2 max-h-64 rounded-xl object-contain"/></a> : isVoiceAttachment(attachment) ? <VoiceMessagePlayer key={attachment.uuid} attachment={attachment} isOutgoing={isOutgoing} timestamp={timestamp} read={read}/> : attachment.type === 'video' ? <video key={attachment.uuid} controls preload="metadata" src={attachment.url} className="mt-2 max-h-72 max-w-full rounded-xl"/> : isDocumentAttachment(attachment) ? <DocumentMessageBubble key={attachment.uuid} attachment={attachment} isOutgoing={isOutgoing} timestamp={timestamp} read={read}/> : <a key={attachment.uuid} href={attachment.url} className={`mt-2 flex min-w-0 items-center gap-2 rounded-xl p-2 text-xs font-bold ${isOutgoing ? 'bg-white/15 text-white' : 'bg-slate-100 text-blue-800'}`}><Icon name="book" className="h-4 w-4 shrink-0"/><span className="min-w-0 flex-1 truncate">{attachment.name}</span><span className="shrink-0">{Math.ceil(attachment.size_bytes / 1024)} KB</span></a>)}</>;
}

function ActionMenu({message, canPin, isOutgoing, isPinned, onAction}) {
    const [open, setOpen] = useState(false);
    const [showReactions, setShowReactions] = useState(false);
    if (message.deleted) return null;
    const actions = [['reply', 'Reply'], ['react', 'React'], ['forward', 'Forward'], message.body && ['copy', 'Copy text'], isOutgoing && ['edit', 'Edit'], isOutgoing && ['delete', 'Delete'], canPin && [isPinned ? 'unpin' : 'pin', isPinned ? 'Unpin' : 'Pin']].filter(Boolean);
    return <div className="relative self-center"><button type="button" onClick={() => setOpen(value => !value)} className="rounded-lg p-1.5 text-slate-400 opacity-0 transition hover:bg-slate-200 hover:text-slate-700 group-hover:opacity-100 focus:opacity-100" aria-label="Message actions" aria-expanded={open}><Icon name="more" className="h-4 w-4"/></button>{open && <div role="menu" className="absolute right-0 z-20 mt-1 w-36 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">{actions.map(([action, label]) => <button key={action} type="button" role="menuitem" onClick={() => { if (action === 'react') { setShowReactions(true); return; } setOpen(false); onAction(action, message); }} className={`block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50 ${action === 'delete' ? 'text-rose-700' : 'text-slate-700'}`}>{label}</button>)}</div>}{showReactions && <div aria-label="Choose reaction" className="absolute right-0 z-30 mt-1 flex rounded-xl border border-slate-200 bg-white p-1 shadow-lg">{['👍', '❤️', '😂', '😮', '😢', '👏'].map(emoji => <button key={emoji} type="button" onClick={() => { setShowReactions(false); setOpen(false); onAction('reactEmoji', {message, emoji}); }} className="rounded-lg p-1.5 text-base hover:bg-blue-50" aria-label={`React with ${emoji}`}>{emoji}</button>)}</div>}</div>;
}

export default function MessageBubble({message, currentUser, isGroup, canPin, isPinned, onAction, messageRef}) {
    const isOutgoing = String(message.sender.id) === String(currentUser.id);
    const attachments = message.attachments || [];
    const voiceOnly = !message.body && attachments.length === 1 && isVoiceAttachment(attachments[0]);
    const hasDocument = attachments.some(isDocumentAttachment);
    return <article ref={messageRef} id={`message-${message.id}`} className={`group flex gap-2.5 ${isOutgoing ? 'justify-end' : ''}`}><>{!isOutgoing && <UserAvatar name={message.sender.name} avatarUrl={message.sender.avatar_url} size="sm"/>}</><div className="flex max-w-[85%] items-center gap-1 sm:max-w-[78%]"><div className={`min-w-0 rounded-2xl px-3.5 py-2.5 text-sm shadow-sm ${isOutgoing ? 'bg-blue-800 text-white' : 'bg-white text-slate-800'}`}>{isGroup && !isOutgoing && <p className="text-xs font-bold text-blue-700">{message.sender.name}</p>}{message.reply && <button type="button" onClick={() => onAction('focus', {id: message.reply.id})} className={`mt-1 block max-w-full border-l-2 pl-2 text-left text-xs ${isOutgoing ? 'border-white/70 text-white/80' : 'border-blue-400 text-slate-500'}`}><span className="block font-bold">{message.reply.sender_name}</span><span className="block truncate">{message.reply.unavailable ? 'Message unavailable' : message.reply.body || 'Attachment'}</span></button>}{message.deleted ? <p className="flex items-center gap-1.5 italic opacity-75"><Icon name="x" className="h-3.5 w-3.5"/>Message deleted</p> : <>{!hasDocument && message.body && <p className="mt-1 whitespace-pre-wrap break-words">{message.body}</p>}<Attachments attachments={attachments} isOutgoing={isOutgoing} timestamp={formatTime(message.created_at)} read={message.read}/>{hasDocument && message.body && <p className="mt-2 whitespace-pre-wrap break-words">{message.body}</p>}{message.reactions && Object.keys(message.reactions).length > 0 && <div className="mt-2 flex flex-wrap gap-1">{Object.entries(message.reactions).map(([emoji, count]) => <button type="button" key={emoji} onClick={() => onAction('reactEmoji', {message, emoji})} className="rounded-full bg-white/15 px-2 py-0.5 text-xs hover:bg-white/25">{emoji} {count}</button>)}</div>}</>} {!voiceOnly && !hasDocument && <p className="mt-1.5 text-right text-[11px] opacity-70">{formatTime(message.created_at)}{message.edited && !message.deleted ? ' · edited' : ''}</p>}</div><ActionMenu message={message} canPin={canPin} isOutgoing={isOutgoing} isPinned={isPinned} onAction={onAction}/></div></article>;
}

export {attachmentLabel};

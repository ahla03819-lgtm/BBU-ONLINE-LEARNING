import React, {useEffect, useRef, useState} from 'react';
import {useParticipants} from '@livekit/components-react';
import Icon from '../../UI/Icon';
import MeetingParticipantAvatar from './MeetingParticipantAvatar';
import {participantConnectionKey, sortRaisedParticipants} from './meetingView';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {noticeKey, noticeRaw, renderNotice} from '../../../i18n/notice';

function timestamp(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'});
}

export function MeetingSidePanel({title, icon, onClose, children}) {
    const {t} = useTranslation();
    const ref = useRef(null);
    useEffect(() => {
        const previous = document.activeElement;
        ref.current?.querySelector('button')?.focus();
        return () => { if (previous?.isConnected) previous.focus(); };
    }, []);
    const keyDown = (event) => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); onClose(); }
        if (event.key === 'Tab' && window.matchMedia('(max-width: 1279px)').matches) {
            const elements = [...ref.current.querySelectorAll('button:not(:disabled), a[href], textarea:not(:disabled), input:not(:disabled), select:not(:disabled)')];
            const first = elements[0], last = elements.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    };
    return <aside ref={ref} onKeyDown={keyDown} className="flex h-full min-h-0 flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white text-slate-900 shadow-xl shadow-slate-900/10" aria-label={title}>
        <header className="flex shrink-0 items-start justify-between gap-3 border-b border-slate-100 px-5 py-4"><div className="flex items-center gap-3"><span className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-sky-50 text-sky-700"><Icon name={icon} className="h-4 w-4"/></span><h2 className="font-bold">{title}</h2></div><button type="button" onClick={onClose} className="rounded-xl p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-sky-600" aria-label={t('meetingRoom.panels.close', {title})}><Icon name="x" className="h-4 w-4"/></button></header>
        {children}
    </aside>;
}

export function MeetingChatPanel({messages, onClose, onSend, connected, maxMessageLength}) {
    const {t} = useTranslation();
    const [body, setBody] = useState('');
    const [error, setError] = useState(null);
    const endRef = useRef(null);
    useEffect(() => {
        endRef.current?.scrollIntoView({block: 'end'});
    }, [messages]);
    const send = async () => {
        if (!body.trim()) return;
        try { await onSend(body); setBody(''); setError(null); } catch (problem) { setError(noticeRaw(problem.message) || noticeKey('meetingRoom.errors.chatSendFailed')); }
    };
    const keyDown = (event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); send(); } };

    return <MeetingSidePanel title={t('meetingRoom.panels.chatTitle')} icon="messages" onClose={onClose}><div className="min-h-0 flex-1 overflow-y-auto p-4">{messages.length === 0 ? <div className="flex min-h-44 flex-col items-center justify-center px-5 text-center"><span className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-50 text-sky-700"><Icon name="messages" className="h-5 w-5"/></span><h3 className="mt-3 text-sm font-bold text-slate-900">{t('meetingRoom.panels.chatStart')}</h3><p className="mt-1 text-xs leading-5 text-slate-500">{t('meetingRoom.panels.chatHint')}</p></div> : <div className="space-y-3">{messages.map((message) => <article key={message.id} className={`flex gap-2.5 ${message.local ? 'flex-row-reverse' : ''}`}><MeetingParticipantAvatar participant={message.sender} size="sm"/><div className={`max-w-[82%] rounded-2xl px-3 py-2 text-sm shadow-sm ${message.local ? 'bg-sky-700 text-white' : 'bg-slate-100 text-slate-800'}`}><p className={`mb-1 text-[11px] font-bold ${message.local ? 'text-sky-100' : 'text-slate-500'}`}>{message.local ? t('common.you') : message.sender.name}</p><p className="whitespace-pre-wrap break-words leading-5">{message.body}</p><p className={`mt-1 text-right text-[10px] ${message.local ? 'text-sky-100' : 'text-slate-400'}`}>{timestamp(message.sentAt)}</p></div></article>)}</div>}<div ref={endRef}/></div><form className="border-t border-slate-100 p-3" onSubmit={(event) => { event.preventDefault(); send(); }}><label className="sr-only" htmlFor="meeting-chat-message">{t('meetingRoom.panels.chatLabel')}</label><textarea id="meeting-chat-message" rows="2" maxLength={maxMessageLength} value={body} onChange={(event) => setBody(event.target.value)} onKeyDown={keyDown} placeholder={t('meetingRoom.panels.chatPlaceholder')} disabled={!connected} className="field min-h-20 resize-none"/>{error && <p className="mt-2 text-xs font-medium text-rose-700" role="alert">{renderNotice(error, t)}</p>}<div className="mt-2 flex items-center justify-between gap-3"><p className="text-[11px] text-slate-500">{body.length}/{maxMessageLength}</p><button type="submit" disabled={!connected || !body.trim()} className="inline-flex min-h-9 items-center gap-1.5 rounded-xl bg-sky-700 px-3 py-2 text-xs font-bold text-white transition hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50"><Icon name="arrow-right" className="h-3.5 w-3.5"/>{t('meetingRoom.panels.send')}</button></div></form></MeetingSidePanel>;
}

export function WaitingSection({moderation}) {
    const {t} = useTranslation();
    const {requests, waitingLoaded, waitingError, busy, available, decide} = moderation;
    return <section aria-label={t('meetingRoom.waitingRoom.title')} className="mb-4 rounded-2xl border border-amber-200 bg-amber-50/50 p-3">
        <h3 className="text-sm font-bold text-slate-900">{t('meetingRoom.waitingRoom.waitingLabel')}{waitingLoaded ? ` (${requests.length})` : ''}</h3>
        <p className="mt-1 text-xs text-slate-600" role="status">{renderNotice(waitingError, t) || (!available ? t('meetingRoom.waitingRoom.unavailable') : !waitingLoaded ? t('meetingRoom.waitingRoom.loading') : requests.length ? t('meetingRoom.waitingRoom.approveHint') : t('meetingRoom.waitingRoom.empty'))}</p>
        <div className="mt-2 space-y-3">{requests.map((request) => <div key={request.reference} className="rounded-xl bg-white p-3">
            <div className="flex items-center gap-2"><MeetingParticipantAvatar name={request.display_name} avatarUrl={request.avatar_url} size="sm" alt=""/><div className="min-w-0"><p className="break-words text-sm font-bold">{request.display_name}</p><p className="text-xs text-slate-500">{t('common.requested')} {timestamp(request.requested_at)}</p></div></div>
            <div className="mt-3 flex gap-2"><button type="button" disabled={!available || busy !== null} onClick={() => decide(request, 'admitted')} aria-label={t('meetingRoom.panels.admit', {name: request.display_name})} className="min-h-10 flex-1 rounded-lg bg-sky-700 px-3 py-2 text-xs font-bold text-white hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600 disabled:opacity-50">{t('meetingRoom.waitingRoom.admit')}</button><button type="button" disabled={!available || busy !== null} onClick={() => decide(request, 'denied')} aria-label={t('meetingRoom.panels.reject', {name: request.display_name})} className="min-h-10 flex-1 rounded-lg border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-600 disabled:opacity-50">{t('meetingRoom.waitingRoom.rejected')}</button></div>
        </div>)}</div>
    </section>;
}

export function ParticipantsPanel({meeting, onClose, raisedHands, moderation}) {
    const {t} = useTranslation();
    const participants = useParticipants();
    const [keys, setKeys] = useState({});
    const identities = JSON.stringify(participants.map((participant) => participant.identity).sort());
    useEffect(() => {
        let cancelled = false;
        Promise.all(JSON.parse(identities).map(async (identity) => [identity, await participantConnectionKey(meeting.uuid, identity)]))
            .then((pairs) => { if (!cancelled) setKeys(Object.fromEntries(pairs)); }).catch(() => { if (!cancelled) setKeys({}); });
        return () => { cancelled = true; };
    }, [identities, meeting.uuid]);
    const recordsByKey = new Map(moderation.records.filter((record) => !record.removed).map((record) => [record.connection_key, record]));
    const raisedCount = participants.filter((participant) => raisedHands[participant.identity]).length;
    return <MeetingSidePanel title={t('meetingRoom.panels.peopleCount', {count: participants.length})} icon="users" onClose={onClose}><div className="min-h-0 flex-1 overflow-y-auto p-3">
        {moderation.message && <p className="mb-3 rounded-xl bg-sky-50 p-3 text-xs text-sky-900" role="status">{renderNotice(moderation.message, t)}</p>}
        {meeting.can_manage_join_requests && <WaitingSection moderation={moderation}/>}
        <h3 className="mb-2 px-2 text-xs font-bold uppercase tracking-wide text-slate-500">{t('meetingRoom.panels.inThisMeeting')}{raisedCount > 0 ? ` · ${t(raisedCount === 1 ? 'meetingRoom.panels.handsRaised' : 'meetingRoom.panels.handsRaisedPlural', {count: raisedCount})}` : ''}</h3>
        {moderation.rosterError && <p className="mb-2 px-2 text-xs text-amber-800" role="status">{renderNotice(moderation.rosterError, t)}</p>}
        <div className="space-y-2">{sortRaisedParticipants(participants, raisedHands).map((participant) => {
            const record = recordsByKey.get(keys[participant.identity]);
            const raised = Boolean(raisedHands[participant.identity]);
            const removing = moderation.busy?.startsWith('remove:') ? moderation.busy.slice(7) : null;
            return <div key={participant.identity} className={`rounded-2xl border p-3 ${raised ? 'border-amber-200 bg-amber-50/60' : 'border-transparent hover:bg-slate-50'}`}>
                <div className="flex items-center gap-3"><MeetingParticipantAvatar participant={participant} size="sm"/><div className="min-w-0 flex-1"><p className="break-words text-sm font-bold text-slate-900">{participant.name || t('common.participant')}{participant.isLocal ? ` (${t('common.you')})` : ''}</p><p className={`mt-0.5 text-xs font-medium ${record?.is_host ? 'text-violet-700' : 'text-slate-500'}`}>{record?.is_host ? t('meetingRoom.panels.organizer') : t('common.participant')}</p></div>{raised && <span className="rounded-lg bg-amber-100 p-1.5 text-amber-800" role="img" aria-label={t('meetingRoom.panels.handRaised')}><Icon name="hand" className="h-4 w-4"/></span>}</div>
                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-600"><span className="inline-flex items-center gap-1" aria-label={t(participant.isMicrophoneEnabled ? 'meetingRoom.panels.micStateOn' : 'meetingRoom.panels.micStateMuted')}><Icon name={participant.isMicrophoneEnabled ? 'mic' : 'mic-off'} className="h-3.5 w-3.5"/>{t(participant.isMicrophoneEnabled ? 'meetingRoom.panels.micOn' : 'meetingRoom.panels.micMuted')}</span><span className="inline-flex items-center gap-1" aria-label={t(participant.isCameraEnabled ? 'meetingRoom.panels.cameraOn' : 'meetingRoom.panels.cameraOff')}><Icon name={participant.isCameraEnabled ? 'video' : 'video-off'} className="h-3.5 w-3.5"/>{t(participant.isCameraEnabled ? 'meetingRoom.panels.cameraOn' : 'meetingRoom.panels.cameraOff')}</span>{participant.isScreenShareEnabled && <span className="inline-flex items-center gap-1 font-bold text-sky-700"><Icon name="screen" className="h-3.5 w-3.5"/>{t('meetingRoom.panels.sharingScreen')}</span>}</div>
                {meeting.can_manage_participants && record?.can_remove && !participant.isLocal && <button type="button" disabled={!moderation.available || moderation.busy !== null} onClick={() => moderation.remove(record)} className="mt-2 min-h-9 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus:ring-2 focus:ring-rose-600 disabled:opacity-50" aria-label={t('meetingRoom.panels.removeParticipant', {name: participant.name || t('common.participant')})}>{t(removing === record.reference ? 'meetingRoom.panels.removing' : 'meetingRoom.panels.remove')}</button>}
            </div>;
        })}</div>
    </div></MeetingSidePanel>;
}

export function HostControlsPanel({meeting, moderation, onClose, onPeople, onInfo}) {
    const {t} = useTranslation();
    if (!meeting.can_end && !meeting.can_manage_participants && !meeting.can_manage_join_requests) return null;
    const actionClass = 'min-h-11 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-left text-sm font-bold hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600';
    return <MeetingSidePanel title={t('meetingRoom.panels.hostControls')} icon="settings" onClose={onClose}><div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
        {moderation.message && <p role="status" className="rounded-xl bg-sky-50 p-3 text-sm text-sky-900">{renderNotice(moderation.message, t)}</p>}
        {meeting.can_manage_join_requests && <WaitingSection moderation={moderation}/>}
        {meeting.can_manage_participants && <button type="button" className={actionClass} onClick={onPeople}>{t('meetingRoom.panels.manageParticipants')}</button>}
        <button type="button" className={actionClass} onClick={onInfo} aria-label={t('meetingRoom.controlCenter.openMeetingInfo')}>{t('meetingRoom.controlCenter.meetingInfo')}</button>
        {meeting.can_end && <div className="border-t border-slate-100 pt-4"><p className="mb-3 text-xs leading-5 text-slate-600">{t('meetingRoom.panels.endWarning')}</p><button type="button" disabled={!moderation.available || moderation.busy !== null} onClick={moderation.end} className="min-h-11 w-full rounded-xl bg-rose-700 px-3 py-2.5 text-sm font-bold text-white hover:bg-rose-800 focus:outline-none focus:ring-2 focus:ring-rose-500 disabled:opacity-50">{t(moderation.busy === 'end' ? 'meetingRoom.panels.ending' : 'meetingRoom.panels.endForEveryone')}</button></div>}
    </div></MeetingSidePanel>;
}

export function MeetingInfoPanel({meeting, schoolClass, count, meetingLink, onCopy, copied, onClose}) {
    const {t} = useTranslation();
    const date = (value) => value && Number.isFinite(new Date(value).getTime()) ? new Date(value).toLocaleString() : t('common.notSet');
    return <MeetingSidePanel title={t('meetingRoom.panels.meetingInfo')} icon="calendar" onClose={onClose}><div className="min-h-0 flex-1 space-y-5 overflow-y-auto p-5">
        <h3 className="break-words text-lg font-bold text-slate-900">{meeting.title}</h3>
        <dl className="space-y-4 text-sm">{[['meetingRoom.panels.class', `${schoolClass.name}${schoolClass.section ? ` · ${schoolClass.section}` : ''}`], ['meetingRoom.panels.starts', date(meeting.scheduled_start_at)], ['meetingRoom.panels.ends', date(meeting.scheduled_end_at)], ['meetingRoom.panels.status', t(`meetings.status.${meeting.status}`)], ['common.participants', count]].map(([label, value]) => <div key={label}><dt className="text-xs font-semibold text-slate-500">{t(label)}</dt><dd className="mt-1 break-words font-medium">{value}</dd></div>)}</dl>
        {meetingLink && <div><label htmlFor="meeting-invite-link" className="text-xs font-bold text-slate-600">{t('meetingRoom.panels.meetingLink')}</label><input id="meeting-invite-link" readOnly value={meetingLink} onFocus={(event) => event.target.select()} className="mt-2 w-full min-w-0 rounded-xl border border-slate-200 p-3 text-xs focus:outline-none focus:ring-2 focus:ring-sky-600"/><button type="button" onClick={onCopy} aria-label={t('meetingRoom.controlCenter.copyMeetingLink')} className="mt-3 min-h-11 w-full rounded-xl bg-sky-700 px-3 py-2.5 text-sm font-bold text-white hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600">{t('meetingRoom.controlCenter.copyMeetingLink')}</button><p className="mt-2 text-xs text-slate-500" role="status">{renderNotice(copied, t) || t('meetingRoom.panels.joinPermission')}</p></div>}
    </div></MeetingSidePanel>;
}

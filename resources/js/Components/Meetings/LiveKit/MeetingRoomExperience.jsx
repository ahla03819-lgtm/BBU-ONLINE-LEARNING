import React, {useEffect, useRef, useState} from 'react';
import {GridLayout, LiveKitRoom, ParticipantTile, RoomAudioRenderer, StartAudio, TrackToggle, useConnectionState, useLocalParticipant, useParticipants, useTracks, useTrackToggle} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import '@livekit/components-styles';

const initials = (name = 'Participant') => name.split(/\s+/).map((part) => part[0]).join('').slice(0, 2).toUpperCase();

function Tiles() {
    const cameras = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);
    const screens = useTracks([{source: Track.Source.ScreenShare, withPlaceholder: false}]);
    const tileStyle = 'edway-live-tile';

    return <div className="flex min-h-0 flex-1 flex-col gap-3">
        {screens.length > 0 && <section className="min-h-0 flex-[3] rounded-2xl border border-white/10 bg-black/30 p-2" aria-label="Shared screen">
            <p className="mb-2 inline-flex items-center gap-2 rounded-lg bg-violet-500/25 px-3 py-1.5 text-xs font-semibold text-violet-50"><Icon name="screen" className="h-4 w-4"/>Screen shared by {screens[0].participant.name || 'Participant'}</p>
            <GridLayout tracks={screens} className={`h-full min-h-72 overflow-hidden rounded-xl ${tileStyle}`}><ParticipantTile/></GridLayout>
        </section>}
        <section className={`min-h-0 ${screens.length > 0 ? 'max-h-64 flex-1' : 'flex-1'}`} aria-label="Participant cameras">
            <GridLayout tracks={cameras} className={`h-full min-h-[25rem] overflow-hidden rounded-2xl ${tileStyle}`}><ParticipantTile/></GridLayout>
        </section>
    </div>;
}

function ConnectionStatus({error}) {
    const state = useConnectionState();
    const labels = {connecting: 'Connecting…', connected: 'Connected', reconnecting: 'Reconnecting…', signalReconnecting: 'Reconnecting…', disconnected: 'Disconnected'};
    const text = error || labels[state] || 'Connecting…';
    const variant = error || state === 'disconnected' ? 'bg-red-400 text-red-300' : state === 'connected' ? 'bg-emerald-400 text-emerald-300' : 'bg-amber-300 text-amber-300';

    return <p className="inline-flex items-center gap-2 text-xs font-medium text-slate-200" aria-live="polite"><span className={`h-2.5 w-2.5 rounded-full ${variant} ${state !== 'connected' && !error ? 'animate-pulse' : ''}`}/>{text}</p>;
}

function RoomSummary() {
    const participants = useParticipants();

    return <div className="flex items-center gap-2 rounded-xl bg-white/[.1] px-3 py-2 text-sm font-semibold text-white" aria-label={`${participants.length} meeting participants`}><Icon name="users" className="h-4 w-4"/>{participants.length}</div>;
}

function WaitingRoomRequests({meeting, schoolClass}) {
    const [requests, setRequests] = useState([]);
    const [deciding, setDeciding] = useState(null);
    const [message, setMessage] = useState('');
    const url = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room/requests`;

    useEffect(() => {
        const refresh = () => fetch(url, {headers: {Accept: 'application/json'}})
            .then((response) => response.ok ? response.json() : null)
            .then((data) => data && setRequests(data.requests));
        refresh();
        const interval = window.setInterval(refresh, 5000);

        return () => window.clearInterval(interval);
    }, [url]);

    const decide = async (reference, decision) => {
        if (deciding) return;
        setDeciding(reference); setMessage('');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`${url}/${reference}`, {method: 'PATCH', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({decision})});
            if (!response.ok) throw new Error();
            setRequests((items) => items.filter((request) => request.reference !== reference));
            setMessage(decision === 'admitted' ? 'Participant admitted.' : 'Participant denied.');
        } catch { setMessage('Unable to update this join request. Please try again.'); }
        finally { setDeciding(null); }
    };

    return <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex items-center justify-between gap-3"><div><h2 className="text-sm font-bold text-slate-950">Waiting room</h2><p className="mt-1 text-xs text-slate-500">{requests.length ? `${requests.length} participant${requests.length === 1 ? '' : 's'} waiting` : 'No requests right now'}</p></div><span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span className="h-2 w-2 rounded-full bg-emerald-500"/>Auto refresh</span></div>{message && <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700" role="status">{message}</p>}<div className="mt-3 space-y-3">{requests.length === 0 ? <p className="rounded-xl bg-violet-50 px-3 py-3 text-sm text-slate-600">No participants are waiting for approval.</p> : requests.map((request) => <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-3" key={request.reference}><div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-violet-100 text-xs font-bold text-violet-700">{initials(request.display_name)}</span><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-900">{request.display_name}</p><p className="mt-1 text-xs text-slate-500">Requested {new Date(request.requested_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</p></div></div><div className="mt-3 flex justify-end gap-2"><button className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'admitted')}>Admit</button><button className="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 transition hover:bg-red-50 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'denied')}>Deny</button></div></div>)}</div></section>;
}

function ParticipantRail({records, canManage, onRemove, removing}) {
    const liveParticipants = useParticipants();
    const liveByName = new Map(liveParticipants.map((participant) => [participant.name, participant]));
    const visible = records.filter((record) => !record.removed);

    return <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex items-center justify-between"><h2 className="text-sm font-bold text-slate-950">Participants ({liveParticipants.length})</h2><Icon name="users" className="h-4 w-4 text-violet-600"/></div><div className="mt-3 space-y-3">{visible.length === 0 ? <p className="rounded-xl bg-slate-50 px-3 py-3 text-sm text-slate-600">No participants are connected yet.</p> : visible.map((record) => {
        const participant = liveByName.get(record.display_name);
        const microphoneOn = participant?.isMicrophoneEnabled;
        const label = record.role === 'host' ? 'Host' : record.present ? 'Present' : 'Not present';
        return <div key={record.reference} className="flex items-center gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-violet-100 to-indigo-50 text-xs font-bold text-violet-700">{initials(record.display_name)}</span><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-900">{record.display_name}</p><p className={`mt-0.5 text-xs font-medium ${record.role === 'host' ? 'text-violet-600' : 'text-slate-500'}`}>{label}</p></div>{participant && <Icon name={microphoneOn ? 'mic' : 'mic-off'} className={`h-4 w-4 ${microphoneOn ? 'text-emerald-600' : 'text-red-500'}`}/>} {canManage && record.role !== 'host' && <button className="rounded-lg border border-slate-200 p-1.5 text-slate-500 transition hover:border-red-200 hover:bg-red-50 hover:text-red-600 disabled:opacity-50" disabled={removing !== null} onClick={() => onRemove(record.reference)} aria-label={removing === record.reference ? `Removing… ${record.display_name}` : `Remove ${record.display_name}`}>{removing === record.reference ? <Icon name="loader" className="h-4 w-4 animate-spin"/> : <Icon name="x" className="h-4 w-4"/>}</button>}</div>;
    })}</div></section>;
}

function MediaControls({meeting, onLeave, onMediaMessage}) {
    const connection = useConnectionState();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled, isScreenShareEnabled} = useLocalParticipant();
    const screen = useTrackToggle({source: Track.Source.ScreenShare, onDeviceError: () => onMediaMessage('Screen sharing was cancelled or is unavailable in this browser.')});
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef(null);
    const available = connection === 'connected' && meeting.status === 'active';
    const stopAll = async () => {
        await Promise.allSettled([localParticipant.setCameraEnabled(false), localParticipant.setMicrophoneEnabled(false), localParticipant.setScreenShareEnabled(false)]);
    };
    useEffect(() => {
        if (['ending', 'ended', 'cancelled'].includes(meeting.status)) stopAll().finally(onLeave);
    }, [meeting.status]);
    useEffect(() => () => { localParticipant.setScreenShareEnabled(false).catch(() => {}); }, [localParticipant]);
    useEffect(() => {
        const close = (event) => { if (!menuRef.current?.contains(event.target)) setMenuOpen(false); };
        const escape = (event) => { if (event.key === 'Escape') setMenuOpen(false); };
        document.addEventListener('mousedown', close); document.addEventListener('keydown', escape);
        return () => { document.removeEventListener('mousedown', close); document.removeEventListener('keydown', escape); };
    }, []);
    const leave = async () => { await stopAll(); onLeave(); };
    const control = 'flex min-h-[4.5rem] min-w-[4.9rem] flex-col items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-[11px] font-semibold text-white shadow-sm transition focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:cursor-not-allowed disabled:opacity-40';
    const mediaControl = (enabled) => `${control} ${enabled ? 'border-white/[.12] bg-white/[.1] hover:border-white/20 hover:bg-white/[.18]' : 'border-red-300/25 bg-red-500/[.16] text-red-50 hover:bg-red-500/[.24]'}`;

    return <div className="relative mx-auto mt-4 flex w-fit max-w-full flex-wrap justify-center gap-2 rounded-2xl border border-white/[.08] bg-[#10131c]/95 p-2.5 shadow-2xl shadow-black/40" ref={menuRef}>
        <TrackToggle source={Track.Source.Microphone} showIcon={false} disabled={!available} className={mediaControl(isMicrophoneEnabled)} aria-label={isMicrophoneEnabled ? 'Mute microphone' : 'Unmute microphone'} data-media-state={isMicrophoneEnabled ? 'Microphone active' : 'Microphone muted'} aria-pressed={isMicrophoneEnabled}><Icon name={isMicrophoneEnabled ? 'mic' : 'mic-off'} className="h-5 w-5"/>{isMicrophoneEnabled ? 'Mic' : 'Muted'}</TrackToggle>
        <TrackToggle source={Track.Source.Camera} showIcon={false} disabled={!available} className={mediaControl(isCameraEnabled)} aria-label={isCameraEnabled ? 'Turn camera off' : 'Turn camera on'} aria-pressed={isCameraEnabled}><Icon name={isCameraEnabled ? 'video' : 'video-off'} className="h-5 w-5"/>{isCameraEnabled ? 'Camera' : 'Camera off'}</TrackToggle>
        <button className={`${control} ${menuOpen ? 'border-violet-300/60 bg-violet-600 shadow-[0_0_0_2px_rgba(139,92,246,.65)]' : 'border-white/[.12] bg-white/[.1] hover:border-white/20 hover:bg-white/[.18]'}`} type="button" onClick={() => setMenuOpen((open) => !open)} aria-label="More meeting controls" aria-haspopup="menu" aria-expanded={menuOpen}><Icon name="more" className="h-5 w-5"/>More</button>
        <button className="ml-1 flex min-h-[4.5rem] min-w-[4.9rem] flex-col items-center justify-center gap-1.5 rounded-xl border border-red-400/30 bg-red-600 px-3 py-2 text-[11px] font-semibold text-white shadow-lg shadow-red-950/30 transition hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-300 disabled:cursor-not-allowed disabled:opacity-40 sm:ml-2" onClick={leave} disabled={connection === 'disconnected'}><Icon name="logout" className="h-5 w-5"/>Leave</button>
        {menuOpen && <div className="absolute bottom-[calc(100%+.75rem)] left-1/2 z-20 w-56 -translate-x-1/2 rounded-2xl border border-white/10 bg-[#171a23] p-2 shadow-2xl shadow-black/50" role="menu" aria-label="More meeting controls">
            {meeting.can_screen_share && <button {...screen.buttonProps} className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-white transition hover:bg-white/10 disabled:opacity-50" disabled={!available || screen.pending} onClick={(event) => { screen.buttonProps.onClick?.(event); setMenuOpen(false); }} role="menuitem"><Icon name="screen" className="h-5 w-5 text-violet-300"/>{screen.pending ? 'Updating…' : isScreenShareEnabled ? 'Stop sharing screen' : 'Share screen'}</button>}
        </div>}
    </div>;
}

export default function MeetingRoomExperience({credentials, meeting, schoolClass, initialMedia, onLeave}) {
    const connected = useRef(false);
    const [connectionError, setConnectionError] = useState('');
    const [mediaMessage, setMediaMessage] = useState('');
    const [participants, setParticipants] = useState([]);
    const [removing, setRemoving] = useState(null);
    const [moderationMessage, setModerationMessage] = useState('');
    const participantsUrl = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/participants`;
    useEffect(() => { fetch(participantsUrl, {headers: {Accept: 'application/json'}}).then((response) => response.ok ? response.json() : null).then((data) => data && setParticipants(data.participants)); }, []);
    const remove = async (reference) => {
        if (removing) return;
        setRemoving(reference); setModerationMessage('');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`${participantsUrl}/${reference}`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            if (!response.ok) throw new Error();
            setParticipants((items) => items.map((item) => item.reference === reference ? {...item, removed: true} : item));
            setModerationMessage('Participant removed.');
        } catch { setModerationMessage('Unable to remove the participant. Please try again.'); }
        finally { setRemoving(null); }
    };

    return <LiveKitRoom token={credentials.token} serverUrl={credentials.server_url} connect audio={initialMedia.microphone} video={initialMedia.camera}
        onConnected={() => { connected.current = true; setConnectionError(''); }}
        onError={() => setConnectionError('Unable to join the meeting. Please try again.')}
        onDisconnected={() => { if (connected.current) onLeave(); else setConnectionError('Unable to join the meeting. Please try again.'); }}
        className="edway-live-room grid min-h-[calc(100vh-8rem)] gap-4 bg-transparent text-white xl:grid-cols-[minmax(0,1fr)_22rem]">
        <section className="relative flex min-h-[42rem] min-w-0 flex-col overflow-hidden rounded-3xl border border-slate-800 bg-[radial-gradient(circle_at_18%_12%,rgba(104,91,224,.24),transparent_34%),linear-gradient(145deg,#171b28,#0a0d14_70%)] p-3 shadow-[0_24px_64px_rgba(20,19,50,.32)]">
            <header className="relative z-10 flex flex-wrap items-start justify-between gap-3 rounded-2xl bg-black/20 px-3 py-2.5"><div className="min-w-0"><p className="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-white"><span className="grid h-9 w-9 place-items-center rounded-xl bg-white/10"><Icon name="video" className="h-5 w-5"/></span>EDWAY LIVE CLASS</p><p className="mt-1.5 truncate pl-11 text-sm font-medium text-slate-200">{schoolClass.name}{schoolClass.section ? ` · ${schoolClass.section}` : ''}{meeting.subject ? ` · ${meeting.subject.name}` : ''}</p><div className="mt-1.5 pl-11"><ConnectionStatus error={connectionError}/></div></div><div className="flex items-center gap-2"><RoomSummary/><StartAudio label="Enable meeting audio" className="rounded-xl bg-violet-600 px-3 py-2 text-xs font-semibold text-white hover:bg-violet-500"/></div></header>
            {(mediaMessage || moderationMessage) && <div className="relative z-10 mt-3 rounded-xl border border-amber-300/25 bg-amber-300/10 px-4 py-3 text-sm text-amber-100" role="status">{mediaMessage || moderationMessage}</div>}
            <div className="relative z-0 min-h-0 flex-1 py-3"><Tiles/></div>
            <RoomAudioRenderer/><MediaControls meeting={meeting} onLeave={onLeave} onMediaMessage={setMediaMessage}/>
        </section>
        <aside className="order-first grid content-start gap-4 xl:order-none xl:max-h-[calc(100vh-8rem)] xl:overflow-y-auto xl:pr-1">{meeting.can_manage_join_requests && <WaitingRoomRequests meeting={meeting} schoolClass={schoolClass}/>}<ParticipantRail records={participants} canManage={meeting.can_manage_participants} onRemove={remove} removing={removing}/></aside>
    </LiveKitRoom>;
}

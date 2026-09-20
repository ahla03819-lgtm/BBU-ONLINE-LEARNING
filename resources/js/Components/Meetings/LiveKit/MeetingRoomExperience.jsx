import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {ConnectionQualityIndicator, GridLayout, LiveKitRoom, ParticipantName, ParticipantTile, RoomAudioRenderer, StartAudio, TrackMutedIndicator, TrackToggle, useConnectionState, useLocalParticipant, useParticipants, useSpeakingParticipants, useTrackRefContext, useTracks, VideoTrack} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import MeetingParticipantAvatar from './MeetingParticipantAvatar';
import {useAppSounds} from '../../../Sound/AppSounds';
import useMeetingEphemeralSignals from '../../../Hooks/Meetings/useMeetingEphemeralSignals';
import useMeetingModeration from '../../../Hooks/Meetings/useMeetingModeration';
import MeetingControlCenter, {MeetingDeviceSettings} from './MeetingControlCenter';
import MeetingStage from './MeetingStage';
import {HostControlsPanel, MeetingChatPanel, MeetingInfoPanel, ParticipantsPanel} from './MeetingSidePanel';
import {authorizedMeetingLink, localCameraTrackClass} from './meetingView';
import '@livekit/components-styles';

const MEETING_HISTORY_GUARD = '__edwayMeetingHistoryGuard';

function useMeetingNavigationGuard(active) {
    const guardId = useRef(globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`);

    useEffect(() => {
        if (!active) return;

        const url = window.location.href;
        const originalState = window.history.state;
        const state = originalState && typeof originalState === 'object' ? originalState : {};

        window.history.replaceState({...state, [MEETING_HISTORY_GUARD]: guardId.current}, '', url);

        const restoreMeeting = (event) => {
            event.stopImmediatePropagation();

            if (event.state?.[MEETING_HISTORY_GUARD] === guardId.current) return;

            window.history.forward();
        };

        window.addEventListener('popstate', restoreMeeting, {capture: true});

        return () => {
            window.removeEventListener('popstate', restoreMeeting, {capture: true});
            if (window.history.state?.[MEETING_HISTORY_GUARD] === guardId.current && window.location.href === url) {
                window.history.replaceState(originalState, '', url);
            }
        };
    }, [active]);
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

function MeetingRoomSounds({meeting}) {
    const connection = useConnectionState();
    const participants = useParticipants();
    const sounds = useAppSounds();
    const knownParticipants = useRef(null);
    const previousConnection = useRef(connection);
    const previousMeetingStatus = useRef(meeting.status);

    useEffect(() => {
        if (connection !== 'connected') {
            if (previousConnection.current === 'connected' && ['reconnecting', 'signalReconnecting', 'disconnected'].includes(connection)) sounds.play('reconnect', meeting.uuid);
            previousConnection.current = connection;
            return;
        }

        const current = new Set(participants.filter((participant) => !participant.isLocal).map((participant) => participant.identity));
        if (knownParticipants.current) {
            current.forEach((identity) => { if (!knownParticipants.current.has(identity)) sounds.play('participant-joined', `${meeting.uuid}:${identity}`); });
            knownParticipants.current.forEach((identity) => { if (!current.has(identity)) sounds.play('participant-left', `${meeting.uuid}:${identity}`); });
        }
        knownParticipants.current = current;
        previousConnection.current = connection;
    }, [connection, meeting.uuid, participants, sounds]);

    useEffect(() => {
        if (previousMeetingStatus.current === 'active' && ['ending', 'ended', 'cancelled'].includes(meeting.status)) sounds.play('meeting-ended', meeting.uuid);
        previousMeetingStatus.current = meeting.status;
    }, [meeting.status, meeting.uuid, sounds]);

    return null;
}

function WaitingRoomRequests({meeting, schoolClass}) {
    const [requests, setRequests] = useState([]);
    const [deciding, setDeciding] = useState(null);
    const [message, setMessage] = useState('');
    const url = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room/requests`;
    const sounds = useAppSounds();
    const knownRequests = useRef(null);

    useEffect(() => {
        const refresh = () => fetch(url, {headers: {Accept: 'application/json'}})
            .then((response) => response.ok ? response.json() : null)
            .then((data) => {
                if (!data) return;
                const references = data.requests.map((request) => request.reference);
                if (knownRequests.current) references.filter((reference) => !knownRequests.current.has(reference)).forEach((reference) => sounds.play('waiting-room-request', `${meeting.uuid}:${reference}`));
                knownRequests.current = new Set(references);
                setRequests(data.requests);
            });
        refresh();
        const interval = window.setInterval(refresh, 5000);

        return () => window.clearInterval(interval);
    }, [meeting.uuid, sounds, url]);

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

    return <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex items-center justify-between gap-3"><div><h2 className="text-sm font-bold text-slate-950">Waiting room</h2><p className="mt-1 text-xs text-slate-500">{requests.length ? `${requests.length} participant${requests.length === 1 ? '' : 's'} waiting` : 'No requests right now'}</p></div><span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span className="h-2 w-2 rounded-full bg-emerald-500"/>Auto refresh</span></div>{message && <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700" role="status">{message}</p>}<div className="mt-3 space-y-3">{requests.length === 0 ? <p className="rounded-xl bg-violet-50 px-3 py-3 text-sm text-slate-600">No participants are waiting for approval.</p> : requests.map((request) => <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-3" key={request.reference}><div className="flex items-start gap-3"><MeetingParticipantAvatar name={request.display_name} avatarUrl={request.avatar_url} size="md" alt=""/><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-900">{request.display_name}</p><p className="mt-1 text-xs text-slate-500">Requested {new Date(request.requested_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</p></div></div><div className="mt-3 flex justify-end gap-2"><button className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'admitted')}>Admit</button><button className="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 transition hover:bg-red-50 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'denied')}>Deny</button></div></div>)}</div></section>;
}

function useMeetingElapsedTime() {
    const connection = useConnectionState();
    const [sessionStartedAt, setSessionStartedAt] = useState(null);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (connection !== 'connected') return;

        setSessionStartedAt((startedAt) => startedAt ?? Date.now());
    }, [connection]);

    useEffect(() => {
        if (sessionStartedAt === null) return;

        const interval = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(interval);
    }, [sessionStartedAt]);

    if (sessionStartedAt === null) return null;

    const seconds = Math.max(0, Math.floor((now - sessionStartedAt) / 1000));
    if (!Number.isFinite(seconds)) return null;
    const minutes = Math.floor(seconds / 60);
    return `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

function ElapsedTime({value}) {
    if (!value) return null;

    return <span className="font-mono text-xs font-bold tabular-nums text-slate-200">{value}</span>;
}

function ReactionOverlay({events}) {
    if (!events.length) return null;
    return <div className="pointer-events-none absolute inset-x-0 top-24 z-20 flex flex-col items-center gap-2" aria-live="polite">{events.map((event) => <div key={event.id} className="animate-[bounce_1s_ease-in-out] rounded-full border border-white/20 bg-slate-950/70 px-4 py-2 text-lg shadow-xl backdrop-blur"><span>{event.reaction}</span><span className="ml-2 text-xs font-bold text-white">{event.sender.name}</span></div>)}</div>;
}

function MiniMeetingWindow({meeting, schoolClass, elapsedTime, connectionError, onLeave, onReturn}) {
    const connection = useConnectionState();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled} = useLocalParticipant();
    const participants = useParticipants();
    const speakers = useSpeakingParticipants();
    const screens = useTracks([{source: Track.Source.ScreenShare, withPlaceholder: false}]);
    const cameras = useTracks([{source: Track.Source.Camera, withPlaceholder: false}]);
    const preview = screens[0] || cameras.find((track) => !track.participant.isLocal) || cameras[0];
    const featuredParticipant = speakers[0] || preview?.participant || participants.find((participant) => !participant.isLocal) || localParticipant;
    const labels = {connecting: 'Connecting…', connected: 'Connected', reconnecting: 'Reconnecting…', signalReconnecting: 'Reconnecting…', disconnected: 'Disconnected'};
    const status = connectionError || labels[connection] || 'Connecting…';
    const available = connection === 'connected' && meeting.status === 'active';
    const leave = async () => {
        await Promise.allSettled([localParticipant.setCameraEnabled(false), localParticipant.setMicrophoneEnabled(false), localParticipant.setScreenShareEnabled(false)]);
        onLeave();
    };

    return <aside className="fixed inset-x-3 bottom-3 z-[70] overflow-hidden rounded-2xl border border-slate-700 bg-slate-950 text-white shadow-2xl shadow-black/50 sm:inset-x-auto sm:bottom-5 sm:right-5 sm:w-[22rem]" aria-label="Mini meeting window">
        <button type="button" onClick={onReturn} className="relative block aspect-video w-full overflow-hidden bg-[radial-gradient(circle_at_50%_25%,#374151,#111827_70%)] text-left focus:outline-none focus:ring-2 focus:ring-inset focus:ring-violet-300" aria-label="Return to meeting">
            {preview?.publication ? <VideoTrack trackRef={preview} className={`h-full w-full object-cover ${localCameraTrackClass(preview)}`}/> : <div className="flex h-full flex-col items-center justify-center gap-3 px-6 text-center text-slate-300"><MeetingParticipantAvatar participant={featuredParticipant} size="lg"/><p className="text-sm font-semibold">Meeting is still running</p><p className="text-xs text-slate-400">Video will appear when a participant shares it.</p></div>}
            <div className="pointer-events-none absolute inset-x-0 top-0 flex items-center justify-between gap-2 bg-gradient-to-b from-black/70 to-transparent p-3"><span className="max-w-[15rem] truncate text-sm font-bold">{meeting.title}</span><ConnectionStatus error={connectionError}/></div>
            <div className="pointer-events-none absolute bottom-3 left-3 rounded-lg bg-black/60 px-2 py-1 text-xs font-bold text-white">{status}{elapsedTime && ` · ${elapsedTime}`}</div>
        </button>
        <div className="flex flex-wrap items-center justify-between gap-2 p-3">
            <div className="flex items-center gap-1.5" aria-label="Device controls">
                <TrackToggle source={Track.Source.Camera} showIcon={false} disabled={!available} className="rounded-xl border border-white/15 bg-white/10 p-2 text-white transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={isCameraEnabled ? 'Turn camera off' : 'Turn camera on'} aria-pressed={isCameraEnabled}><Icon name={isCameraEnabled ? 'video' : 'video-off'} className="h-5 w-5"/></TrackToggle>
                <TrackToggle source={Track.Source.Microphone} showIcon={false} disabled={!available} className="rounded-xl border border-white/15 bg-white/10 p-2 text-white transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={isMicrophoneEnabled ? 'Mute microphone' : 'Unmute microphone'} aria-pressed={isMicrophoneEnabled}><Icon name={isMicrophoneEnabled ? 'mic' : 'mic-off'} className="h-5 w-5"/></TrackToggle>
            </div>
            <div className="flex items-center gap-2"><button type="button" onClick={onReturn} className="rounded-xl bg-violet-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-300">Return to meeting</button><button type="button" onClick={leave} className="rounded-xl border border-rose-400/40 bg-rose-600/15 px-3 py-2 text-xs font-bold text-rose-100 transition hover:bg-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-300">Leave</button></div>
        </div>
        <p className="sr-only">{schoolClass.name} meeting controls</p>
    </aside>;
}

function RoomContent({meeting, schoolClass, onLeave, onReturn, mode, mediaMessage, onMediaMessage, connectionError}) {
    const [panel, setPanel] = useState(null);
    const [view, setView] = useState('gallery');
    const [copied, setCopied] = useState('');
    const connection = useConnectionState();
    const participants = useParticipants();
    const signals = useMeetingEphemeralSignals();
    const elapsedTime = useMeetingElapsedTime();
    const participantIdentities = useMemo(() => participants.map((participant) => participant.identity).sort().join(','), [participants]);
    const moderation = useMeetingModeration({meeting, schoolClass, connected: connection === 'connected', participantIdentities});
    const meetingLink = useMemo(() => authorizedMeetingLink(meeting, schoolClass, window.location.origin), [meeting, schoolClass]);
    const raisedCount = participants.filter((participant) => signals.raisedHands[participant.identity]).length;
    const copyMeetingLink = useCallback(async () => {
        if (!meetingLink || !navigator.clipboard?.writeText) {
            setCopied('Copying the meeting link is unavailable in this browser.');
            return;
        }
        try {
            await navigator.clipboard.writeText(meetingLink);
            setCopied('Meeting link copied.');
        } catch {
            setCopied('Unable to copy the meeting link.');
        }
    }, [meetingLink]);

    if (mode === 'mini') return <><MeetingRoomSounds meeting={meeting}/><RoomAudioRenderer/><MiniMeetingWindow meeting={meeting} schoolClass={schoolClass} elapsedTime={elapsedTime} connectionError={connectionError} onReturn={onReturn} onLeave={onLeave}/></>;

    return <><MeetingRoomSounds meeting={meeting}/><div className={`fixed inset-x-0 bottom-0 top-14 z-10 grid overflow-y-auto bg-slate-950 p-3 sm:p-4 lg:left-56 lg:p-6 ${panel ? 'xl:grid-cols-[minmax(0,1fr)_22rem]' : ''}`}>
        <section className="relative flex min-h-[42rem] min-w-0 flex-col overflow-hidden rounded-3xl border border-slate-800 bg-[radial-gradient(circle_at_18%_12%,rgba(104,91,224,.24),transparent_34%),linear-gradient(145deg,#171b28,#0a0d14_70%)] p-3 shadow-[0_24px_64px_rgba(20,19,50,.32)]">
            <header className="relative z-10 flex flex-wrap items-start justify-between gap-3 rounded-2xl bg-black/20 px-3 py-2.5"><div className="min-w-0"><p className="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-white"><span className="grid h-9 w-9 place-items-center rounded-xl bg-white/10"><Icon name="video" className="h-5 w-5"/></span>BBU LIVE CLASS</p><p className="mt-1.5 truncate pl-11 text-sm font-medium text-slate-200">{schoolClass.name}{schoolClass.section ? ` · ${schoolClass.section}` : ''}{meeting.subject ? ` · ${meeting.subject.name}` : ''}</p><div className="mt-1.5 flex items-center gap-3 pl-11"><ConnectionStatus error={connectionError}/><ElapsedTime value={elapsedTime}/></div></div><div className="flex items-center gap-2"><RoomSummary/><StartAudio label="Enable meeting audio" className="rounded-xl bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-600"/></div></header>
            <ReactionOverlay events={signals.reactionEvents}/>
            {(mediaMessage || moderation.message) && <div className="relative z-10 mt-3 rounded-xl border border-amber-300/25 bg-amber-300/10 px-4 py-3 text-sm text-amber-100" role="status">{mediaMessage || moderation.message}</div>}
            <div className="relative z-0 min-h-0 flex-1 py-3"><MeetingStage view={view} onViewChange={setView}/></div>
            <RoomAudioRenderer/>
            <MeetingControlCenter meeting={meeting} activePanel={panel} onPanelChange={setPanel} signals={signals} waitingCount={moderation.requests.length} raisedCount={raisedCount} view={view} onViewChange={setView} onCopyLink={copyMeetingLink} copied={copied} hasMeetingLink={Boolean(meetingLink)} onLeave={onLeave} onMessage={onMediaMessage}/>
        </section>
        {panel && <aside className="min-h-0 xl:max-h-[calc(100vh-8rem)] xl:overflow-y-auto xl:pl-4">{panel === 'chat' ? <MeetingChatPanel messages={signals.messages} onClose={() => setPanel(null)} onSend={signals.sendMessage} connected={signals.connected} maxMessageLength={signals.maxMessageLength}/> : panel === 'people' ? <ParticipantsPanel meeting={meeting} onClose={() => setPanel(null)} raisedHands={signals.raisedHands} moderation={moderation}/> : panel === 'host' ? <HostControlsPanel meeting={meeting} moderation={moderation} onClose={() => setPanel(null)} onPeople={() => setPanel('people')} onInfo={() => setPanel('info')}/> : panel === 'info' ? <MeetingInfoPanel meeting={meeting} schoolClass={schoolClass} count={participants.length} meetingLink={meetingLink} onCopy={copyMeetingLink} copied={copied} onClose={() => setPanel(null)}/> : panel === 'devices' ? <MeetingDeviceSettings onClose={() => setPanel(null)} onMessage={onMediaMessage}/> : null}</aside>}
    </div></>;
}

export default function MeetingRoomExperience({credentials, meeting, schoolClass, initialMedia, mode = 'full', onReturn, onLeave}) {
    const connected = useRef(false);
    useMeetingNavigationGuard(meeting.status === 'active' && mode === 'full');
    const [connectionError, setConnectionError] = useState('');
    const [mediaMessage, setMediaMessage] = useState('');

    const audio = initialMedia.microphone ? (initialMedia.microphoneId ? {deviceId: initialMedia.microphoneId} : true) : false;
    const video = initialMedia.camera ? (initialMedia.cameraId ? {deviceId: initialMedia.cameraId} : true) : false;

    return <LiveKitRoom token={credentials.token} serverUrl={credentials.server_url} connect audio={audio} video={video}
        onConnected={() => { connected.current = true; setConnectionError(''); }}
        onError={() => setConnectionError('Unable to join the meeting. Please try again.')}
        onDisconnected={() => { if (connected.current) onLeave(); else setConnectionError('Unable to join the meeting. Please try again.'); }}
        className="edway-live-room overscroll-x-none bg-transparent text-white">
        <RoomContent meeting={meeting} schoolClass={schoolClass} onLeave={onLeave} onReturn={onReturn} mode={mode} mediaMessage={mediaMessage} onMediaMessage={setMediaMessage} connectionError={connectionError}/>
    </LiveKitRoom>;
}

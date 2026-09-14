import React, {useEffect, useRef, useState} from 'react';
import {ConnectionQualityIndicator, GridLayout, LiveKitRoom, ParticipantName, ParticipantTile, RoomAudioRenderer, StartAudio, TrackMutedIndicator, useConnectionState, useParticipants, useTrackRefContext, useTracks, VideoTrack} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import UserAvatar from '../../UI/UserAvatar';
import MeetingParticipantAvatar from './MeetingParticipantAvatar';
import {useAppSounds} from '../../../Sound/AppSounds';
import useMeetingEphemeralSignals from '../../../Hooks/Meetings/useMeetingEphemeralSignals';
import MeetingControlCenter from './MeetingControlCenter';
import {MeetingChatPanel, ParticipantsPanel} from './MeetingSidePanel';
import '@livekit/components-styles';

function CameraParticipantTile() {
    const trackRef = useTrackRefContext();

    return <ParticipantTile>
        {trackRef.publication && <VideoTrack trackRef={trackRef}/>}
        <div className="lk-participant-placeholder bg-[radial-gradient(circle_at_50%_30%,#374151,#171923_65%)]">
            <MeetingParticipantAvatar participant={trackRef.participant}/>
        </div>
        <div className="lk-participant-metadata">
            <div className="lk-participant-metadata-item">
                <TrackMutedIndicator trackRef={{participant: trackRef.participant, source: Track.Source.Microphone}} show="muted"/>
                <ParticipantName/>
            </div>
            <ConnectionQualityIndicator className="lk-participant-metadata-item"/>
        </div>
    </ParticipantTile>;
}

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
            <GridLayout tracks={cameras} className={`h-full min-h-[25rem] overflow-hidden rounded-2xl ${tileStyle}`}><CameraParticipantTile/></GridLayout>
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

    return <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex items-center justify-between gap-3"><div><h2 className="text-sm font-bold text-slate-950">Waiting room</h2><p className="mt-1 text-xs text-slate-500">{requests.length ? `${requests.length} participant${requests.length === 1 ? '' : 's'} waiting` : 'No requests right now'}</p></div><span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span className="h-2 w-2 rounded-full bg-emerald-500"/>Auto refresh</span></div>{message && <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700" role="status">{message}</p>}<div className="mt-3 space-y-3">{requests.length === 0 ? <p className="rounded-xl bg-violet-50 px-3 py-3 text-sm text-slate-600">No participants are waiting for approval.</p> : requests.map((request) => <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-3" key={request.reference}><div className="flex items-start gap-3"><UserAvatar name={request.display_name} avatarUrl={request.avatar_url} size="md" alt=""/><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-900">{request.display_name}</p><p className="mt-1 text-xs text-slate-500">Requested {new Date(request.requested_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</p></div></div><div className="mt-3 flex justify-end gap-2"><button className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'admitted')}>Admit</button><button className="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 transition hover:bg-red-50 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'denied')}>Deny</button></div></div>)}</div></section>;
}

function ElapsedTime({startedAt}) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const interval = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(interval);
    }, []);
    if (!startedAt) return null;
    const seconds = Math.max(0, Math.floor((now - new Date(startedAt).getTime()) / 1000));
    if (!Number.isFinite(seconds)) return null;
    const minutes = Math.floor(seconds / 60);
    return <span className="font-mono text-xs font-bold tabular-nums text-slate-200">{String(Math.floor(minutes / 60)).padStart(2, '0')}:{String(minutes % 60).padStart(2, '0')}:{String(seconds % 60).padStart(2, '0')}</span>;
}

function ReactionOverlay({events}) {
    if (!events.length) return null;
    return <div className="pointer-events-none absolute inset-x-0 top-24 z-20 flex flex-col items-center gap-2" aria-live="polite">{events.map((event) => <div key={event.id} className="animate-[bounce_1s_ease-in-out] rounded-full border border-white/20 bg-slate-950/70 px-4 py-2 text-lg shadow-xl backdrop-blur"><span>{event.reaction}</span><span className="ml-2 text-xs font-bold text-white">{event.sender.name}</span></div>)}</div>;
}

function RoomContent({meeting, schoolClass, participantRecords, canRemove, onRemove, removing, onLeave, mediaMessage, onMediaMessage, moderationMessage, connectionError}) {
    const [panel, setPanel] = useState(null);
    const signals = useMeetingEphemeralSignals();

    return <><MeetingRoomSounds meeting={meeting}/><div className={`grid min-h-[calc(100vh-8rem)] gap-4 ${panel || meeting.can_manage_join_requests ? 'xl:grid-cols-[minmax(0,1fr)_22rem]' : ''}`}>
        <section className="relative flex min-h-[42rem] min-w-0 flex-col overflow-hidden rounded-3xl border border-slate-800 bg-[radial-gradient(circle_at_18%_12%,rgba(104,91,224,.24),transparent_34%),linear-gradient(145deg,#171b28,#0a0d14_70%)] p-3 shadow-[0_24px_64px_rgba(20,19,50,.32)]">
            <header className="relative z-10 flex flex-wrap items-start justify-between gap-3 rounded-2xl bg-black/20 px-3 py-2.5"><div className="min-w-0"><p className="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-white"><span className="grid h-9 w-9 place-items-center rounded-xl bg-white/10"><Icon name="video" className="h-5 w-5"/></span>BBU LIVE CLASS</p><p className="mt-1.5 truncate pl-11 text-sm font-medium text-slate-200">{schoolClass.name}{schoolClass.section ? ` · ${schoolClass.section}` : ''}{meeting.subject ? ` · ${meeting.subject.name}` : ''}</p><div className="mt-1.5 flex items-center gap-3 pl-11"><ConnectionStatus error={connectionError}/><ElapsedTime startedAt={meeting.actual_start_at}/></div></div><div className="flex items-center gap-2"><RoomSummary/><StartAudio label="Enable meeting audio" className="rounded-xl bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-600"/></div></header>
            <ReactionOverlay events={signals.reactionEvents}/>
            {(mediaMessage || moderationMessage) && <div className="relative z-10 mt-3 rounded-xl border border-amber-300/25 bg-amber-300/10 px-4 py-3 text-sm text-amber-100" role="status">{mediaMessage || moderationMessage}</div>}
            <div className="relative z-0 min-h-0 flex-1 py-3"><Tiles/></div>
            <RoomAudioRenderer/>
            <MeetingControlCenter meeting={meeting} schoolClass={schoolClass} activePanel={panel} onPanelChange={setPanel} signals={signals} onLeave={onLeave} onMessage={onMediaMessage}/>
        </section>
        {(panel || meeting.can_manage_join_requests) && <aside className="min-h-0 xl:max-h-[calc(100vh-8rem)] xl:overflow-y-auto xl:pr-1">{panel === 'chat' ? <MeetingChatPanel messages={signals.messages} onClose={() => setPanel(null)} onSend={signals.sendMessage} connected={signals.connected} maxMessageLength={signals.maxMessageLength}/> : panel === 'people' ? <ParticipantsPanel meeting={meeting} onClose={() => setPanel(null)} raisedHands={signals.raisedHands} records={participantRecords} canManage={canRemove} onRemove={onRemove} removing={removing}/> : meeting.can_manage_join_requests ? <WaitingRoomRequests meeting={meeting} schoolClass={schoolClass}/> : null}</aside>}
    </div></>;
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

    const audio = initialMedia.microphone ? (initialMedia.microphoneId ? {deviceId: initialMedia.microphoneId} : true) : false;
    const video = initialMedia.camera ? (initialMedia.cameraId ? {deviceId: initialMedia.cameraId} : true) : false;

    return <LiveKitRoom token={credentials.token} serverUrl={credentials.server_url} connect audio={audio} video={video}
        onConnected={() => { connected.current = true; setConnectionError(''); }}
        onError={() => setConnectionError('Unable to join the meeting. Please try again.')}
        onDisconnected={() => { if (connected.current) onLeave(); else setConnectionError('Unable to join the meeting. Please try again.'); }}
        className="edway-live-room bg-transparent text-white">
        <RoomContent meeting={meeting} schoolClass={schoolClass} participantRecords={participants} canRemove={meeting.can_manage_participants} onRemove={remove} removing={removing} onLeave={onLeave} mediaMessage={mediaMessage} onMediaMessage={setMediaMessage} moderationMessage={moderationMessage} connectionError={connectionError}/>
    </LiveKitRoom>;
}

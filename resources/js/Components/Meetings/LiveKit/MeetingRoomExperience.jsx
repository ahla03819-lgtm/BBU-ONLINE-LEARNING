import React, {useEffect, useRef, useState} from 'react';
import {GridLayout, LiveKitRoom, ParticipantTile, RoomAudioRenderer, StartAudio, TrackToggle, useConnectionState, useLocalParticipant, useParticipants, useTracks, useTrackToggle} from '@livekit/components-react';
import {Track} from 'livekit-client';
import '@livekit/components-styles';

function Tiles() {
    const cameras = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);
    const screens = useTracks([{source: Track.Source.ScreenShare, withPlaceholder: false}]);

    return <div className="flex min-h-0 flex-1 flex-col gap-3">{screens.length>0&&<section className="min-h-0 flex-[3]" aria-label="Shared screen"><p className="mb-2 text-sm font-medium">Screen shared by {screens[0].participant.name || 'Participant'}</p><GridLayout tracks={screens} className="h-full min-h-72">{<ParticipantTile />}</GridLayout></section>}<section className={`min-h-0 ${screens.length>0?'max-h-56 flex-1':'flex-1'}`} aria-label="Participant cameras"><GridLayout tracks={cameras} className="h-full">{<ParticipantTile />}</GridLayout></section></div>;
}

function ConnectionStatus({error}) {
    const state = useConnectionState();
    const labels = {connecting: 'Connecting…', connected: 'Connected', reconnecting: 'Reconnecting…', signalReconnecting: 'Reconnecting…', disconnected: 'Disconnected'};

    return <p className="text-sm text-slate-300" aria-live="polite">{error || labels[state] || 'Connecting…'}</p>;
}

function ParticipantMediaStates() {
    const participants = useParticipants();

    return <div className="mt-3 rounded bg-slate-900 p-3"><h2 className="font-medium">Live media</h2>{participants.map((participant) => <p key={participant.sid} className="mt-2 text-sm">{participant.name || 'Participant'} · {participant.isCameraEnabled?'Camera on':'Camera off'} · {participant.isMicrophoneEnabled?'Microphone on':'Microphone muted'}</p>)}</div>;
}

function ParticipantRoster({records, canManage, onRemove, removing}) {
    return <aside className="mt-3 rounded bg-slate-900 p-3"><h2 className="font-medium">Participants</h2>{records.map((record) => <div key={record.reference} className="mt-2 flex items-center justify-between gap-3 text-sm"><span>{record.display_name} · {record.role} · {record.present?'Present':'Not present'}</span>{canManage&&!record.removed&&record.role!=='host'&&<button className="rounded border border-red-400 px-2 py-1 disabled:opacity-50" disabled={removing!==null} onClick={()=>onRemove(record.reference)} aria-label={`Remove ${record.display_name}`}>{removing===record.reference?'Removing…':'Remove'}</button>}</div>)}</aside>;
}

function MediaControls({meeting, onLeave, onMediaMessage}) {
    const connection = useConnectionState();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled, isScreenShareEnabled} = useLocalParticipant();
    const screen = useTrackToggle({source: Track.Source.ScreenShare, onDeviceError: () => onMediaMessage('Screen sharing was cancelled or is unavailable in this browser.')});
    const available = connection === 'connected' && meeting.status === 'active';
    const stopAll = async () => {
        await Promise.allSettled([
            localParticipant.setCameraEnabled(false),
            localParticipant.setMicrophoneEnabled(false),
            localParticipant.setScreenShareEnabled(false),
        ]);
    };
    useEffect(() => {
        if (['ending', 'ended', 'cancelled'].includes(meeting.status)) {
            stopAll().finally(onLeave);
        }
    }, [meeting.status]);
    useEffect(() => () => { localParticipant.setScreenShareEnabled(false).catch(() => {}); }, [localParticipant]);
    const leave = async () => { await stopAll(); onLeave(); };

    return <div className="mt-3 flex flex-wrap justify-center gap-3"><TrackToggle source={Track.Source.Camera} disabled={!available} aria-label={isCameraEnabled?'Turn camera off':'Turn camera on'} aria-pressed={isCameraEnabled}>{isCameraEnabled?'Camera on':'Camera off'}</TrackToggle><TrackToggle source={Track.Source.Microphone} disabled={!available} aria-label={isMicrophoneEnabled?'Mute microphone':'Unmute microphone'} aria-pressed={isMicrophoneEnabled}>{isMicrophoneEnabled?'Microphone on':'Microphone muted'}</TrackToggle>{meeting.can_screen_share&&<button {...screen.buttonProps} disabled={!available||screen.pending} aria-label={isScreenShareEnabled?'Stop sharing screen':'Share screen'} aria-pressed={isScreenShareEnabled}>{screen.pending?'Updating screen share…':isScreenShareEnabled?'Stop sharing screen':'Share screen'}</button>}<button className="lk-button" onClick={leave} disabled={connection==='disconnected'}>Leave meeting</button></div>;
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
        className="flex min-h-[70vh] flex-col rounded-xl bg-slate-950 p-3 text-white">
        <div className="mb-3 flex items-center justify-between"><div><h1 className="font-semibold">{meeting.title}</h1><ConnectionStatus error={connectionError}/>{mediaMessage&&<p className="mt-1 text-sm text-amber-300" role="status">{mediaMessage}</p>}{moderationMessage&&<p className="mt-1 text-sm text-slate-200" role="status">{moderationMessage}</p>}</div><StartAudio label="Enable meeting audio" /></div>
        <Tiles /><RoomAudioRenderer />
        <MediaControls meeting={meeting} onLeave={onLeave} onMediaMessage={setMediaMessage}/>
        <ParticipantMediaStates/>
        {participants.length>0&&<ParticipantRoster records={participants} canManage={meeting.can_manage_participants} onRemove={remove} removing={removing}/>}
    </LiveKitRoom>;
}

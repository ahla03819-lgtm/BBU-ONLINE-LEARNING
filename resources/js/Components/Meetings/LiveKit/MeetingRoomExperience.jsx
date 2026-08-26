import React, {useEffect, useRef, useState} from 'react';
import {DisconnectButton, GridLayout, LiveKitRoom, ParticipantTile, RoomAudioRenderer, StartAudio, TrackToggle, useConnectionState, useParticipants, useTracks} from '@livekit/components-react';
import {Track} from 'livekit-client';
import '@livekit/components-styles';

function Tiles() {
    const tracks = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);
    return <GridLayout tracks={tracks} className="min-h-0 flex-1">{<ParticipantTile />}</GridLayout>;
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

function ParticipantRoster({records, canManage, onRemove}) {
    return <aside className="mt-3 rounded bg-slate-900 p-3"><h2 className="font-medium">Participants</h2>{records.map((record) => <div key={record.reference} className="mt-2 flex items-center justify-between gap-3 text-sm"><span>{record.display_name} · {record.role}</span>{canManage&&!record.removed&&record.role!=='host'&&<button className="rounded border border-red-400 px-2 py-1" onClick={()=>onRemove(record.reference)} aria-label={`Remove ${record.display_name}`}>Remove</button>}</div>)}</aside>;
}

export default function MeetingRoomExperience({credentials, meeting, schoolClass, initialMedia, onLeave}) {
    const connected = useRef(false);
    const [connectionError, setConnectionError] = useState('');
    const [participants, setParticipants] = useState([]);
    const participantsUrl = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/participants`;
    useEffect(() => { fetch(participantsUrl, {headers: {Accept: 'application/json'}}).then((response) => response.ok ? response.json() : null).then((data) => data && setParticipants(data.participants)); }, []);
    const remove = async (reference) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(`${participantsUrl}/${reference}`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
        if (response.ok) setParticipants((items) => items.map((item) => item.reference === reference ? {...item, removed: true} : item));
    };
    return <LiveKitRoom token={credentials.token} serverUrl={credentials.server_url} connect audio={initialMedia.microphone} video={initialMedia.camera}
        onConnected={() => { connected.current = true; setConnectionError(''); }}
        onError={() => setConnectionError('Unable to join the meeting. Please try again.')}
        onDisconnected={() => { if (connected.current) onLeave(); else setConnectionError('Unable to join the meeting. Please try again.'); }}
        className="flex min-h-[70vh] flex-col rounded-xl bg-slate-950 p-3 text-white">
        <div className="mb-3 flex items-center justify-between"><div><h1 className="font-semibold">{meeting.title}</h1><ConnectionStatus error={connectionError}/></div><StartAudio label="Enable meeting audio" /></div>
        <Tiles /><RoomAudioRenderer />
        <div className="mt-3 flex flex-wrap justify-center gap-3"><TrackToggle source={Track.Source.Camera}>Camera</TrackToggle><TrackToggle source={Track.Source.Microphone}>Microphone</TrackToggle><DisconnectButton onClick={onLeave}>Leave meeting</DisconnectButton></div>
        <ParticipantMediaStates/>
        {participants.length>0&&<ParticipantRoster records={participants} canManage={meeting.can_manage_participants} onRemove={remove}/>}
    </LiveKitRoom>;
}

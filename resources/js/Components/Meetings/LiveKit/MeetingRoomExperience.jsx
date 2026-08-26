import React, {useEffect, useState} from 'react';
import {DisconnectButton, GridLayout, LiveKitRoom, ParticipantTile, RoomAudioRenderer, StartAudio, TrackToggle, useTracks} from '@livekit/components-react';
import {Track} from 'livekit-client';
import '@livekit/components-styles';

function Tiles() {
    const tracks = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);
    return <GridLayout tracks={tracks} className="min-h-0 flex-1">{<ParticipantTile />}</GridLayout>;
}

export default function MeetingRoomExperience({credentials, meeting, schoolClass, initialMedia, onLeave}) {
    const [connection, setConnection] = useState('connecting');
    const [participants, setParticipants] = useState([]);
    const participantsUrl = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/participants`;
    useEffect(() => { if (!meeting.can_manage_participants) return; fetch(participantsUrl, {headers: {Accept: 'application/json'}}).then((response) => response.ok ? response.json() : null).then((data) => data && setParticipants(data.participants)); }, []);
    const remove = async (reference) => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(`${participantsUrl}/${reference}`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
        if (response.ok) setParticipants((items) => items.map((item) => item.reference === reference ? {...item, removed: true} : item));
    };
    return <LiveKitRoom token={credentials.token} serverUrl={credentials.server_url} connect audio={initialMedia.microphone} video={initialMedia.camera}
        onConnected={() => setConnection('connected')} onReconnecting={() => setConnection('reconnecting')}
        onDisconnected={() => { setConnection('disconnected'); onLeave(); }} className="flex min-h-[70vh] flex-col rounded-xl bg-slate-950 p-3 text-white">
        <div className="mb-3 flex items-center justify-between"><div><h1 className="font-semibold">{meeting.title}</h1><p className="text-sm text-slate-300" aria-live="polite">{connection}</p></div><StartAudio label="Enable meeting audio" /></div>
        <Tiles /><RoomAudioRenderer />
        <div className="mt-3 flex flex-wrap justify-center gap-3"><TrackToggle source={Track.Source.Camera}>Camera</TrackToggle><TrackToggle source={Track.Source.Microphone}>Microphone</TrackToggle><DisconnectButton onClick={onLeave}>Leave meeting</DisconnectButton></div>
        {meeting.can_manage_participants && participants.length > 0 && <aside className="mt-3 rounded bg-slate-900 p-3"><h2 className="font-medium">Participants</h2>{participants.map((item) => <div key={item.reference} className="mt-2 flex items-center justify-between text-sm"><span>{item.display_name} · {item.role}</span>{!item.removed && item.role !== 'host' && <button className="rounded border border-red-400 px-2 py-1" onClick={() => remove(item.reference)} aria-label={`Remove ${item.display_name}`}>Remove</button>}</div>)}</aside>}
    </LiveKitRoom>;
}

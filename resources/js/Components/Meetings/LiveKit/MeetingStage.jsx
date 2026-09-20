import React, {useEffect, useState} from 'react';
import {ConnectionQualityIndicator, GridLayout, ParticipantName, ParticipantTile, TrackMutedIndicator, useSpeakingParticipants, useTrackRefContext, useTracks, VideoTrack} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import MeetingParticipantAvatar from './MeetingParticipantAvatar';
import {localCameraTrackClass, selectMeetingStage} from './meetingView';

function CameraContent() {
    const trackRef = useTrackRefContext();
    return <>
        {trackRef.publication && <VideoTrack trackRef={trackRef} className={localCameraTrackClass(trackRef)}/>}
        <div className="lk-participant-placeholder bg-[radial-gradient(circle_at_50%_30%,#374151,#171923_65%)]"><MeetingParticipantAvatar participant={trackRef.participant}/></div>
        <div className="lk-participant-metadata"><div className="lk-participant-metadata-item"><TrackMutedIndicator trackRef={{participant: trackRef.participant, source: Track.Source.Microphone}} show="muted"/><ParticipantName/></div><ConnectionQualityIndicator className="lk-participant-metadata-item"/></div>
    </>;
}

function CameraTile({trackRef, onFocus, focusedIdentity}) {
    const focused = focusedIdentity === trackRef.participant.identity;
    return <ParticipantTile trackRef={trackRef} onClick={() => onFocus(trackRef.participant.identity)}>
        <CameraContent/>
        <button type="button" onClick={(event) => { event.stopPropagation(); onFocus(trackRef.participant.identity); }} aria-label={`${focused ? 'Unfocus' : 'Focus'} participant ${trackRef.participant.name || 'Participant'}`} aria-pressed={focused} className="absolute right-2 top-2 z-10 rounded-lg bg-slate-950/80 px-2 py-1.5 text-xs font-bold text-white hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-300"><Icon name={focused ? 'users' : 'user'} className="h-4 w-4"/></button>
    </ParticipantTile>;
}

function GalleryTile(props) {
    const trackRef = useTrackRefContext();
    return trackRef.source === Track.Source.ScreenShare ? <ParticipantTile/> : <CameraTile {...props} trackRef={trackRef}/>;
}

export default function MeetingStage({view, onViewChange}) {
    const cameras = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);
    const screens = useTracks([{source: Track.Source.ScreenShare, withPlaceholder: false}]).filter((track) => !track.publication.isMuted);
    const speakers = useSpeakingParticipants();
    const [focusedIdentity, setFocusedIdentity] = useState(null);
    // Retain the last speaker through short silences instead of jumping to the first tile.
    const [lastSpeaker, setLastSpeaker] = useState(null);
    const speakerIdentity = speakers[0]?.identity;
    useEffect(() => { if (speakerIdentity) setLastSpeaker(speakerIdentity); }, [speakerIdentity]);
    useEffect(() => {
        if (focusedIdentity && !cameras.some((track) => track.participant.identity === focusedIdentity)) setFocusedIdentity(null);
    }, [cameras, focusedIdentity]);
    useEffect(() => { if (view !== 'speaker') setFocusedIdentity(null); }, [view]);
    const focus = (identity) => {
        if (focusedIdentity === identity) { setFocusedIdentity(null); onViewChange('gallery'); }
        else { setFocusedIdentity(identity); onViewChange('speaker'); }
    };
    const stage = selectMeetingStage({view, cameras, screens, speakers: speakers.length ? speakers : [{identity: lastSpeaker}], focusedIdentity});
    const rail = stage.kind === 'screen' ? [...screens.slice(1), ...cameras] : cameras.filter((track) => track !== stage.primary);

    if (stage.kind === 'gallery') return <section className="h-[clamp(22rem,58vh,48rem)] min-w-0" aria-label="Gallery view"><GridLayout tracks={[...screens, ...cameras]} className="h-full min-w-0 overflow-hidden rounded-2xl"><GalleryTile onFocus={focus} focusedIdentity={focusedIdentity}/></GridLayout></section>;

    return <div className="flex min-w-0 flex-col gap-3">
        <section className="min-w-0 rounded-2xl border border-white/10 bg-black/25 p-2" aria-label={stage.kind === 'screen' ? 'Screen-share focus' : 'Speaker / Focus view'}>
            <p className="mb-2 flex items-center gap-2 px-2 text-xs font-semibold text-slate-200"><Icon name={stage.kind === 'screen' ? 'screen' : 'user'} className="h-4 w-4"/>{stage.kind === 'screen' ? `Screen shared by ${stage.primary.participant.name || 'Participant'}` : focusedIdentity ? 'Focused for you' : 'Following the speaker'}</p>
            {view === 'screen' && !screens.length && <p className="mb-2 px-2 text-xs text-slate-300" role="status">No screen is being shared. Showing the speaker until sharing starts.</p>}
            <div className="h-[clamp(19rem,48vh,40rem)] min-w-0">{stage.primary ? stage.kind === 'screen' ? <ParticipantTile trackRef={stage.primary}/> : <CameraTile trackRef={stage.primary} onFocus={focus} focusedIdentity={focusedIdentity}/> : <p className="p-6 text-center text-sm text-slate-300">Waiting for participants…</p>}</div>
        </section>
        {rail.length > 0 && <section className="min-w-0" aria-label="Participant rail"><div className="flex gap-2 overflow-x-auto pb-2">{rail.map((track) => <div key={`${track.participant.identity}:${track.source}`} className="h-36 w-48 shrink-0">{track.source === Track.Source.ScreenShare ? <ParticipantTile trackRef={track}/> : <CameraTile trackRef={track} onFocus={focus} focusedIdentity={focusedIdentity}/>}</div>)}</div></section>}
    </div>;
}

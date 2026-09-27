import React, {useEffect, useRef, useState} from 'react';
import {ConnectionQualityIndicator, GridLayout, ParticipantTile, TrackMutedIndicator, useSpeakingParticipants, useTrackRefContext, useTracks, VideoTrack} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import MeetingParticipantAvatar from './MeetingParticipantAvatar';
import {localCameraTrackClass, selectMeetingStage} from './meetingView';

function getParticipantDisplayName(participant) {
    return participant?.name || participant?.identity || 'Participant';
}

function getParticipantInitials(participant) {
    const name = getParticipantDisplayName(participant);
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'P';
}

function AvatarFallback({participant, size = 'lg'}) {
    const name = getParticipantDisplayName(participant);
    const avatar = participant?.metadata ? (() => {
        try {
            const parsed = JSON.parse(participant.metadata || '{}');
            const value = typeof parsed?.avatar_url === 'string' ? parsed.avatar_url : null;
            if (!value) return null;
            try {
                const url = new URL(value, window.location.origin);
                return url.origin === window.location.origin && url.pathname.startsWith('/storage/user-avatars/') ? url.toString() : null;
            } catch {
                return null;
            }
        } catch {
            return null;
        }
    })() : null;
    const initials = getParticipantInitials(participant);

    if (avatar) {
        return <>
            <img src={avatar} alt={name} className="h-16 w-16 rounded-full object-cover ring-2 ring-white/15" onError={(event) => { event.currentTarget.style.display = 'none'; const next = event.currentTarget.nextElementSibling; if (next) next.style.display = 'flex'; }}/>
            <span className="hidden h-16 w-16 items-center justify-center rounded-full bg-indigo-100 text-lg font-bold text-indigo-700 ring-2 ring-white/15" aria-hidden="true">{initials}</span>
        </>;
    }

    return <span className="flex h-16 w-16 items-center justify-center rounded-full bg-indigo-100 text-lg font-bold text-indigo-700 ring-2 ring-white/15" aria-label={name}>{initials}</span>;
}

function CameraContent() {
    const trackRef = useTrackRefContext();
    const participant = trackRef?.participant;
    const name = getParticipantDisplayName(participant);
    const hasVideoTrack = Boolean(
        participant &&
        trackRef?.publication &&
        trackRef.publication.kind === 'video' &&
        !trackRef.publication.isMuted &&
        participant.isCameraEnabled !== false &&
        !!trackRef.publication.track
    );
    const isMuted = participant?.isMicrophoneEnabled === false;

    return <>
        {hasVideoTrack && <VideoTrack trackRef={trackRef} className={localCameraTrackClass(trackRef)}/>}
        {!hasVideoTrack && <div className="absolute inset-0 z-0 flex items-center justify-center bg-[radial-gradient(circle_at_50%_30%,#2b3442,#111827_70%)]"><MeetingParticipantAvatar participant={participant} name={name} size="lg" className="shadow-2xl shadow-black/30"/></div>}
        <div className="pointer-events-none absolute inset-x-0 bottom-0 z-10 bg-gradient-to-t from-slate-950/70 via-slate-950/20 to-transparent px-2.5 pb-2 pt-5">
            <div className="flex items-center justify-between gap-2">
                <span className="max-w-[calc(100%-2rem)] truncate text-xs font-semibold text-white drop-shadow-sm">{name}</span>
                <span className="inline-flex items-center justify-center text-white/90">
                    {isMuted ? <TrackMutedIndicator trackRef={{participant, source: Track.Source.Microphone}} show="muted"/> : <Icon name="mic" className="h-3.5 w-3.5"/>}
                </span>
            </div>
        </div>
        {hasVideoTrack && <div className="pointer-events-none absolute right-2 top-2 z-20 rounded-full border border-white/10 bg-slate-950/60 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-200">Live</div>}
    </>;
}

function CameraTile({trackRef, onFocus, focusedIdentity}) {
    const participant = trackRef?.participant;
    if (!participant) return null;

    const focused = focusedIdentity === participant.identity;
    return <ParticipantTile trackRef={trackRef} onClick={() => onFocus(participant.identity)} className="relative h-full w-full overflow-hidden rounded-2xl border border-white/10 bg-slate-900 shadow-lg shadow-black/20">
        <CameraContent/>
        <button type="button" onClick={(event) => { event.stopPropagation(); onFocus(participant.identity); }} aria-label={`${focused ? 'Unfocus' : 'Focus'} participant ${participant.name || 'Participant'}`} aria-pressed={focused} className="absolute right-2 top-2 z-30 rounded-lg bg-slate-950/75 px-2 py-1.5 text-xs font-bold text-white hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-300"><Icon name={focused ? 'users' : 'user'} className="h-4 w-4"/></button>
    </ParticipantTile>;
}

function GalleryTile(props) {
    const trackRef = useTrackRefContext();
    return trackRef.source === Track.Source.ScreenShare ? <ParticipantTile/> : <CameraTile {...props} trackRef={trackRef}/>;
}

function ScreenSharePreview({trackRef}) {
    const screenOwner = trackRef?.participant?.name || 'Participant';
    return <div className="relative h-full w-full overflow-hidden rounded-2xl bg-slate-950">
        <VideoTrack trackRef={trackRef} className="h-full w-full object-contain"/>
        <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 bg-gradient-to-t from-black/70 via-black/20 to-transparent px-3 py-2 text-xs font-medium text-white"><span className="truncate">{screenOwner}'s screen</span><span className="inline-flex items-center gap-1 rounded-full border border-white/15 bg-black/30 px-2 py-1 text-[10px] uppercase tracking-[0.18em] text-slate-200"><Icon name="screen" className="h-3.5 w-3.5"/>Shared</span></div>
    </div>;
}

export default function MeetingStage({view, onViewChange}) {
    const cameras = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);
    const screens = useTracks([{source: Track.Source.ScreenShare, withPlaceholder: false}]).filter((track) => !track.publication?.isMuted);
    const speakers = useSpeakingParticipants();
    const [focusedIdentity, setFocusedIdentity] = useState(null);
    const [contentFocus, setContentFocus] = useState(false);
    const [lastSpeaker, setLastSpeaker] = useState(null);
    const previousScreenShare = useRef(false);
    const previousViewRef = useRef(null);
    const validViews = useRef(new Set(['gallery', 'speaker', 'screen']));
    const speakerIdentity = speakers[0]?.identity;
    const screenShareActive = screens.length > 0;
    const effectiveView = screenShareActive ? 'screen' : view;

    useEffect(() => { if (speakerIdentity) setLastSpeaker(speakerIdentity); }, [speakerIdentity]);
    useEffect(() => {
        if (focusedIdentity && !cameras.some((track) => track.participant.identity === focusedIdentity)) setFocusedIdentity(null);
    }, [cameras, focusedIdentity]);
    useEffect(() => { if (view !== 'speaker') setFocusedIdentity(null); }, [view]);
    useEffect(() => {
        const activeView = validViews.current.has(view) ? view : 'gallery';

        if (screenShareActive) {
            if (!previousScreenShare.current) {
                previousViewRef.current = activeView;
                if (view !== 'screen') onViewChange('screen');
            } else if (view !== 'screen') {
                onViewChange('screen');
            }
            previousScreenShare.current = true;
            return;
        }

        if (previousScreenShare.current) {
            const restoredView = previousViewRef.current && validViews.current.has(previousViewRef.current) ? previousViewRef.current : 'gallery';
            if (view !== restoredView) onViewChange(restoredView);
            previousViewRef.current = null;
            previousScreenShare.current = false;
            setContentFocus(false);
        }
    }, [onViewChange, screenShareActive, view]);

    const focus = (identity) => {
        if (focusedIdentity === identity) { setFocusedIdentity(null); onViewChange('gallery'); }
        else { setFocusedIdentity(identity); onViewChange('speaker'); }
    };

    const stage = selectMeetingStage({view: effectiveView, cameras, screens, speakers: speakers.length ? speakers : [{identity: lastSpeaker}], focusedIdentity});
    const rail = screenShareActive ? [...screens.slice(1), ...cameras] : cameras.filter((track) => track !== stage.primary);

    if (stage.kind === 'gallery') return <section className="h-[clamp(22rem,58vh,48rem)] min-w-0" aria-label="Gallery view"><GridLayout tracks={[...screens, ...cameras]} className="h-full min-w-0 overflow-hidden rounded-2xl"><GalleryTile onFocus={focus} focusedIdentity={focusedIdentity}/></GridLayout></section>;

    if (screenShareActive) {
        const railItems = rail.filter(Boolean);
        const showRail = !contentFocus && railItems.length > 0;

        return <section className="min-w-0" aria-label="Shared content layout">
            <div className="flex min-h-0 flex-col gap-3 xl:flex-row">
                <div className="relative min-w-0 flex-1 overflow-hidden rounded-2xl border border-white/10 bg-black/30">
                    <div className="absolute right-3 top-3 z-20 flex items-center justify-end">
                        <button type="button" onClick={() => setContentFocus((value) => !value)} className="rounded-lg border border-white/15 bg-slate-950/70 px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-100 transition hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={contentFocus ? 'Restore participant rail' : 'Focus on content'}>
                            {contentFocus ? 'Show participants' : 'Focus on content'}
                        </button>
                    </div>
                    <div className="h-[clamp(20rem,55vh,44rem)] min-w-0">{stage.primary ? <ScreenSharePreview trackRef={stage.primary}/> : <p className="p-6 text-center text-sm text-slate-300">Waiting for shared content…</p>}</div>
                </div>
                {showRail && <aside className="w-full shrink-0 xl:w-[18rem] 2xl:w-[20rem]" aria-label="Participant rail">
                    <div className="flex max-h-[16rem] gap-2 overflow-x-auto pb-1 xl:max-h-[calc(100vh-18rem)] xl:flex-col xl:overflow-y-auto xl:pb-0">
                        {railItems.map((track) => <div key={`${track.participant.identity}:${track.source}`} className="h-28 min-h-[7rem] w-40 shrink-0 overflow-hidden rounded-2xl border border-white/10 bg-slate-900/80 md:w-48 xl:w-full xl:min-h-[8rem]">
                            {track.source === Track.Source.ScreenShare ? <ParticipantTile trackRef={track}/> : <CameraTile trackRef={track} onFocus={focus} focusedIdentity={focusedIdentity}/>}
                        </div>)}
                    </div>
                </aside>}
            </div>
        </section>;
    }

    return <div className="flex min-w-0 flex-col gap-3">
        <section className="min-w-0 rounded-2xl border border-white/10 bg-black/25 p-2" aria-label={stage.kind === 'screen' ? 'Screen-share focus' : 'Speaker / Focus view'}>
            <p className="mb-2 flex items-center gap-2 px-2 text-xs font-semibold text-slate-200"><Icon name={stage.kind === 'screen' ? 'screen' : 'user'} className="h-4 w-4"/>{stage.kind === 'screen' ? `Screen shared by ${stage.primary.participant.name || 'Participant'}` : focusedIdentity ? 'Focused for you' : 'Following the speaker'}</p>
            {view === 'screen' && !screens.length && <p className="mb-2 px-2 text-xs text-slate-300" role="status">No screen is being shared. Showing the speaker until sharing starts.</p>}
            <div className="h-[clamp(19rem,48vh,40rem)] min-w-0">{stage.primary ? stage.kind === 'screen' ? <ParticipantTile trackRef={stage.primary}/> : <CameraTile trackRef={stage.primary} onFocus={focus} focusedIdentity={focusedIdentity}/> : <p className="p-6 text-center text-sm text-slate-300">Waiting for participants…</p>}</div>
        </section>
        {rail.length > 0 && <section className="min-w-0" aria-label="Participant rail"><div className="flex gap-2 overflow-x-auto pb-2">{rail.map((track) => <div key={`${track.participant.identity}:${track.source}`} className="h-36 w-48 shrink-0">{track.source === Track.Source.ScreenShare ? <ParticipantTile trackRef={track}/> : <CameraTile trackRef={track} onFocus={focus} focusedIdentity={focusedIdentity}/>}</div>)}</div></section>}
    </div>;
}

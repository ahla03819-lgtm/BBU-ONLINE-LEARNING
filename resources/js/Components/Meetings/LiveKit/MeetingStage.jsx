import React, {useEffect, useRef, useState} from 'react';
import {ConnectionQualityIndicator, GridLayout, ParticipantTile, TrackMutedIndicator, useSpeakingParticipants, useTrackRefContext, useTracks, VideoTrack} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import MeetingParticipantAvatar from './MeetingParticipantAvatar';
import {localCameraTrackClass, selectMeetingStage} from './meetingView';
import {useTranslation} from '../../../i18n/LocaleProvider';

function getParticipantDisplayName(participant, fallback = 'Participant') {
    return participant?.name || participant?.identity || fallback;
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
    const {t} = useTranslation();

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
        {hasVideoTrack && <div className="pointer-events-none absolute right-2 top-2 z-20 rounded-full border border-white/10 bg-slate-950/60 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-200">{t('meetingRoom.stage.live')}</div>}
    </>;
}

function CameraTile({trackRef, onFocus, focusedIdentity}) {
    const {t} = useTranslation();
    const participant = trackRef?.participant;
    if (!participant) return null;

    const focused = focusedIdentity === participant.identity;
    return <ParticipantTile trackRef={trackRef} onClick={() => onFocus(participant.identity)} className="relative h-full w-full overflow-hidden rounded-2xl border border-white/10 bg-slate-900 shadow-lg shadow-black/20">
        <CameraContent/>
        <button type="button" onClick={(event) => { event.stopPropagation(); onFocus(participant.identity); }} aria-label={t(focused ? 'meetingRoom.stage.unfocusParticipant' : 'meetingRoom.stage.focusParticipant', {name: participant.name || t('common.participant')})} aria-pressed={focused} className="absolute right-2 top-2 z-30 rounded-lg bg-slate-950/75 px-2 py-1.5 text-xs font-bold text-white hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-300"><Icon name={focused ? 'users' : 'user'} className="h-4 w-4"/></button>
    </ParticipantTile>;
}

function GalleryTile(props) {
    const trackRef = useTrackRefContext();
    return trackRef.source === Track.Source.ScreenShare ? <ScreenShareTile trackRef={trackRef}/> : <CameraTile {...props} trackRef={trackRef}/>;
}

function SharedContentLabel({trackRef}) {
    const {t} = useTranslation();
    const screenOwner = trackRef?.participant?.name || 'Participant';
    return <div className="pointer-events-none absolute inset-x-0 bottom-0 flex items-center justify-between gap-2 bg-gradient-to-t from-black/70 via-black/20 to-transparent px-3 py-2 text-xs font-medium text-white"><span className="truncate">{t('meetingRoom.stage.screenOf', {name: screenOwner})}</span><span className="inline-flex items-center gap-1 rounded-full border border-white/15 bg-black/30 px-2 py-1 text-[10px] uppercase tracking-[0.18em] text-slate-200"><Icon name="screen" className="h-3.5 w-3.5"/>{t('meetingRoom.stage.shared')}</span></div>;
}

function ScreenSharePreview({trackRef}) {
    return <div className="relative h-full w-full overflow-hidden rounded-2xl bg-slate-950">
        <VideoTrack trackRef={trackRef} className="meeting-shared-content"/>
        <SharedContentLabel trackRef={trackRef}/>
    </div>;
}

// Screen shares are never cropped: the tile fills its box and the video is
// letterboxed inside it with object-fit: contain.
function ScreenShareTile({trackRef}) {
    return <ParticipantTile trackRef={trackRef} className="h-full w-full overflow-hidden rounded-2xl bg-slate-950">
        <VideoTrack trackRef={trackRef} className="meeting-shared-content"/>
        <SharedContentLabel trackRef={trackRef}/>
    </ParticipantTile>;
}

export default function MeetingStage({view, onViewChange}) {
    const {t} = useTranslation();
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

    if (stage.kind === 'gallery') return <section className="h-full min-h-0 min-w-0" aria-label={t('meetingRoom.stage.galleryView')}><GridLayout tracks={[...screens, ...cameras]} className="h-full min-w-0 overflow-hidden rounded-2xl"><GalleryTile onFocus={focus} focusedIdentity={focusedIdentity}/></GridLayout></section>;

    if (screenShareActive) {
        const railItems = rail.filter(Boolean);
        const showRail = !contentFocus && railItems.length > 0;

        return <section className="flex h-full min-h-0 min-w-0 flex-col" aria-label={t('meetingRoom.stage.sharedLayout')}>
            <div className="flex min-h-0 flex-1 flex-col gap-3 xl:flex-row">
                <div className="relative flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden rounded-2xl border border-white/10 bg-black/30">
                    <div className="absolute right-3 top-3 z-20 flex items-center justify-end">
                        <button type="button" onClick={() => setContentFocus((value) => !value)} className="rounded-lg border border-white/15 bg-slate-950/70 px-2.5 py-1.5 text-[10px] font-bold uppercase tracking-wide text-slate-100 transition hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={t(contentFocus ? 'meetingRoom.stage.restoreRail' : 'meetingRoom.stage.focusContent')}>
                            {t(contentFocus ? 'meetingRoom.stage.showParticipants' : 'meetingRoom.stage.focusContent')}
                        </button>
                    </div>
                    <div className="min-h-0 min-w-0 flex-1 overflow-hidden">{stage.primary ? <ScreenSharePreview trackRef={stage.primary}/> : <p className="p-6 text-center text-sm text-slate-300">{t('meetingRoom.stage.waitingForShare')}</p>}</div>
                </div>
                {showRail && <aside className="flex w-full shrink-0 flex-col xl:w-[18rem] 2xl:w-[20rem]" aria-label={t('meetingRoom.stage.participantRail')}>
                    <div className="flex max-h-[16rem] gap-2 overflow-x-auto pb-1 xl:min-h-0 xl:max-h-none xl:flex-1 xl:flex-col xl:overflow-y-auto xl:pb-0">
                        {railItems.map((track) => <div key={`${track.participant.identity}:${track.source}`} className="h-28 min-h-[7rem] w-40 shrink-0 overflow-hidden rounded-2xl border border-white/10 bg-slate-900/80 md:w-48 xl:h-32 xl:w-full xl:min-h-[8rem]">
                            {track.source === Track.Source.ScreenShare ? <ScreenShareTile trackRef={track}/> : <CameraTile trackRef={track} onFocus={focus} focusedIdentity={focusedIdentity}/>}
                        </div>)}
                    </div>
                </aside>}
            </div>
        </section>;
    }

    return <div className="flex h-full min-h-0 min-w-0 flex-col gap-3">
        <section className="flex min-h-0 min-w-0 flex-1 flex-col rounded-2xl border border-white/10 bg-black/25 p-2" aria-label={t(stage.kind === 'screen' ? 'meetingRoom.stage.screenFocus' : 'meetingRoom.stage.speakerFocus')}>
            <p className="mb-2 flex shrink-0 items-center gap-2 px-2 text-xs font-semibold text-slate-200"><Icon name={stage.kind === 'screen' ? 'screen' : 'user'} className="h-4 w-4"/>{stage.kind === 'screen' ? t('meetingRoom.stage.sharedBy', {name: stage.primary.participant.name || t('common.participant')}) : focusedIdentity ? t('meetingRoom.stage.focused') : t('meetingRoom.stage.followingSpeaker')}</p>
            {view === 'screen' && !screens.length && <p className="mb-2 shrink-0 px-2 text-xs text-slate-300" role="status">{t('meetingRoom.stage.noShare')}</p>}
            <div className="min-h-0 min-w-0 flex-1 overflow-hidden">{stage.primary ? stage.kind === 'screen' ? <ScreenShareTile trackRef={stage.primary}/> : <CameraTile trackRef={stage.primary} onFocus={focus} focusedIdentity={focusedIdentity}/> : <p className="p-6 text-center text-sm text-slate-300">{t('meetingRoom.stage.waitingForParticipants')}</p>}</div>
        </section>
        {rail.length > 0 && <section className="min-w-0 shrink-0" aria-label={t('meetingRoom.stage.participantRail')}><div className="flex gap-2 overflow-x-auto pb-2">{rail.map((track) => <div key={`${track.participant.identity}:${track.source}`} className="h-36 w-48 shrink-0 overflow-hidden rounded-2xl">{track.source === Track.Source.ScreenShare ? <ScreenShareTile trackRef={track}/> : <CameraTile trackRef={track} onFocus={focus} focusedIdentity={focusedIdentity}/>}</div>)}</div></section>}
    </div>;
}

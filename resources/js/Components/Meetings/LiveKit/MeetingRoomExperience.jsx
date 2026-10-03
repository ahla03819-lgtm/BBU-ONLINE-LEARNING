import React, {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {ConnectionQualityIndicator, GridLayout, LiveKitRoom, ParticipantName, ParticipantTile, RoomAudioRenderer, StartAudio, TrackMutedIndicator, TrackToggle, useConnectionState, useLocalParticipant, useParticipants, useRoomContext, useSpeakingParticipants, useTrackRefContext, useTracks, VideoTrack} from '@livekit/components-react';
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
import {writeMeetingMediaIntent} from './meetingMediaIntent';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {hasNotice, noticeKey, renderFirstNotice, renderNotice} from '../../../i18n/notice';
import {clampMiniWindowPosition, dragMiniWindowPosition, shouldStartMiniWindowDrag} from './meetingMiniWindowPosition';
import {sharedMeetingElapsedSeconds} from './meetingElapsedTime';
import {completeConfirmedMeetingEnd} from './meetingEndTeardown';
import useMeetingFullscreen from '../../../Hooks/Meetings/useMeetingFullscreen';
import useMeetingScreenShareApproval from '../../../Hooks/Meetings/useMeetingScreenShareApproval';
import {useFullscreenContext} from '../../../Providers/FullscreenProvider';
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

function ConnectionStatus({notice}) {
    const {t} = useTranslation();
    const state = useConnectionState();
    // Resolved on every render so a notice already on screen follows the locale.
    const noticeText = renderNotice(notice, t);
    const text = noticeText || t(`meetingRoom.connection.${state}`) || t('meetingRoom.connection.connecting');
    const variant = noticeText || state === 'disconnected' ? 'bg-red-400 text-red-300' : state === 'connected' ? 'bg-emerald-400 text-emerald-300' : 'bg-amber-300 text-amber-300';

    return <p className="inline-flex items-center gap-2 text-xs font-medium text-slate-200" aria-live="polite"><span className={`h-2.5 w-2.5 rounded-full ${variant} ${state !== 'connected' && !noticeText ? 'animate-pulse' : ''}`}/>{text}</p>;
}

function RoomSummary() {
    const {t} = useTranslation();
    const participants = useParticipants();

    return <div className="flex items-center gap-2 rounded-xl bg-white/[.1] px-3 py-2 text-sm font-semibold text-white" aria-label={t('meetingRoom.stage.participantsLabel', {count: participants.length})}><Icon name="users" className="h-4 w-4"/>{participants.length}</div>;
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
    const {t} = useTranslation();
    const [requests, setRequests] = useState([]);
    const [deciding, setDeciding] = useState(null);
    const [message, setMessage] = useState(null);
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
        setDeciding(reference); setMessage(null);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`${url}/${reference}`, {method: 'PATCH', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({decision})});
            if (!response.ok) throw new Error();
            setRequests((items) => items.filter((request) => request.reference !== reference));
            setMessage(noticeKey(decision === 'admitted' ? 'meetingRoom.waitingRoom.admitted' : 'meetingRoom.waitingRoom.denied'));
        } catch { setMessage(noticeKey('meetingRoom.waitingRoom.updateFailed')); }
        finally { setDeciding(null); }
    };

    return <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><div className="flex items-center justify-between gap-3"><div><h2 className="text-sm font-bold text-slate-950">{t('meetingRoom.waitingRoom.title')}</h2><p className="mt-1 text-xs text-slate-500">{requests.length ? t('meetingRoom.waitingRoom.waitingCount', {count: requests.length}) : t('meetingRoom.waitingRoom.noneRightNow')}</p></div><span className="inline-flex items-center gap-1.5 text-xs font-medium text-slate-500"><span className="h-2 w-2 rounded-full bg-emerald-500"/>{t('meetingRoom.waitingRoom.autoRefresh')}</span></div>{hasNotice(message) && <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700" role="status">{renderNotice(message, t)}</p>}<div className="mt-3 space-y-3">{requests.length === 0 ? <p className="rounded-xl bg-violet-50 px-3 py-3 text-sm text-slate-600">{t('meetingRoom.waitingRoom.empty')}</p> : requests.map((request) => <div className="rounded-xl border border-slate-100 bg-slate-50/70 p-3" key={request.reference}><div className="flex items-start gap-3"><MeetingParticipantAvatar name={request.display_name} avatarUrl={request.avatar_url} size="md" alt=""/><div className="min-w-0 flex-1"><p className="truncate text-sm font-semibold text-slate-900">{request.display_name}</p><p className="mt-1 text-xs text-slate-500">{t('common.requested')} {new Date(request.requested_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</p></div></div><div className="mt-3 flex justify-end gap-2"><button className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'admitted')}>{t('meetingRoom.waitingRoom.admit')}</button><button className="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-semibold text-red-600 transition hover:bg-red-50 disabled:opacity-50" disabled={deciding !== null} onClick={() => decide(request.reference, 'denied')}>{t('meetingRoom.waitingRoom.deny')}</button></div></div>)}</div></section>;
}

function useMeetingElapsedTime(meeting, clock) {
    const connection = useConnectionState();
    const [sessionStartedAt, setSessionStartedAt] = useState(null);
    const [now, setNow] = useState(() => performance.now());
    const sharedSeconds = sharedMeetingElapsedSeconds(meeting, clock, now);
    const hasSharedClock = sharedSeconds !== null;

    useEffect(() => {
        if (connection !== 'connected') return;

        setSessionStartedAt((startedAt) => startedAt ?? performance.now());
    }, [connection]);

    useEffect(() => {
        if (!hasSharedClock && sessionStartedAt === null) return;

        const interval = window.setInterval(() => setNow(performance.now()), 1000);

        return () => window.clearInterval(interval);
    }, [sessionStartedAt, hasSharedClock]);

    if (sharedSeconds === null && sessionStartedAt === null) return null;

    const seconds = sharedSeconds ?? Math.max(0, Math.floor((now - sessionStartedAt) / 1000));
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

function MiniMeetingWindow({meeting, schoolClass, elapsedTime, connectionError, mediaMessage, mediaIntentKey, mediaReady, onMessage, onLeave, onReturn, isFullscreen, exitFullscreen}) {
    const {t} = useTranslation();
    const panelRef = useRef(null);
    const dragRef = useRef(null);
    const [position, setPosition] = useState(null);
    const leavingRef = useRef(false);
    const [dragging, setDragging] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const connection = useConnectionState();
    const room = useRoomContext();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled} = useLocalParticipant();
    const participants = useParticipants();
    const speakers = useSpeakingParticipants();
    const screens = useTracks([{source: Track.Source.ScreenShare, withPlaceholder: false}]);
    const cameras = useTracks([{source: Track.Source.Camera, withPlaceholder: false}]);
    const preview = screens[0] || cameras.find((track) => !track.participant.isLocal) || cameras[0];
    const featuredParticipant = speakers[0] || preview?.participant || participants.find((participant) => !participant.isLocal) || localParticipant;
    const status = renderNotice(connectionError, t) || t(`meetingRoom.connection.${connection}`) || t('meetingRoom.connection.connecting');
    const available = connection === 'connected' && meeting.status === 'active';
    const clampToViewport = useCallback(() => {
        const panel = panelRef.current;
        if (!panel) return;

        const bounds = panel.getBoundingClientRect();
        setPosition((current) => current ? clampMiniWindowPosition(current, {width: bounds.width, height: bounds.height}, {width: window.innerWidth, height: window.innerHeight}) : current);
    }, []);

    useEffect(() => {
        window.addEventListener('resize', clampToViewport);
        const observer = typeof ResizeObserver === 'undefined' || !panelRef.current ? null : new ResizeObserver(clampToViewport);
        observer?.observe(panelRef.current);

        return () => {
            window.removeEventListener('resize', clampToViewport);
            observer?.disconnect();
        };
    }, [clampToViewport]);

    const startDrag = (event) => {
        const target = event.target instanceof Element ? event.target : null;
        const targetIsInteractive = Boolean(target?.closest('button, a, input, select, textarea, [role="button"], [data-no-drag]'));
        if (!shouldStartMiniWindowDrag({button: event.button, isPrimary: event.isPrimary, targetIsInteractive})) return;

        const panel = panelRef.current;
        if (!panel) return;
        const bounds = panel.getBoundingClientRect();
        const viewport = {width: window.innerWidth, height: window.innerHeight};
        const origin = clampMiniWindowPosition({left: bounds.left, top: bounds.top}, {width: bounds.width, height: bounds.height}, viewport);
        dragRef.current = {pointerId: event.pointerId, origin, pointerStart: {x: event.clientX, y: event.clientY}};
        setPosition(origin);
        setDragging(true);
        event.currentTarget.setPointerCapture(event.pointerId);
        event.preventDefault();
    };

    const moveDrag = (event) => {
        const drag = dragRef.current;
        const panel = panelRef.current;
        if (!drag || drag.pointerId !== event.pointerId || !panel) return;

        const bounds = panel.getBoundingClientRect();
        setPosition(dragMiniWindowPosition({
            origin: drag.origin,
            pointerStart: drag.pointerStart,
            pointer: {x: event.clientX, y: event.clientY},
            size: {width: bounds.width, height: bounds.height},
            viewport: {width: window.innerWidth, height: window.innerHeight},
        }));
    };

    const stopDrag = (event) => {
        if (!dragRef.current || dragRef.current.pointerId !== event.pointerId) return;
        dragRef.current = null;
        setDragging(false);
        if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
    };

    const moveWithKeyboard = (event) => {
        const moves = {ArrowLeft: [-16, 0], ArrowRight: [16, 0], ArrowUp: [0, -16], ArrowDown: [0, 16]};
        const movement = moves[event.key];
        const panel = panelRef.current;
        if (!movement || !panel) return;

        const bounds = panel.getBoundingClientRect();
        setPosition((current) => clampMiniWindowPosition({
            left: (current?.left ?? bounds.left) + movement[0],
            top: (current?.top ?? bounds.top) + movement[1],
        }, {width: bounds.width, height: bounds.height}, {width: window.innerWidth, height: window.innerHeight}));
        event.preventDefault();
    };

    const leave = async () => {
        if (leavingRef.current) return;
        leavingRef.current = true;
        setLeaving(true);
        try {
            await Promise.allSettled([localParticipant.setCameraEnabled(false), localParticipant.setMicrophoneEnabled(false), localParticipant.setScreenShareEnabled(false)]);
            if (isFullscreen) {
                await exitFullscreen();
            }
            await onLeave(() => room.disconnect());
        } finally {
            leavingRef.current = false;
            setLeaving(false);
        }
    };

    return <aside ref={panelRef} style={position ? {left: position.left, top: position.top} : undefined} className={`fixed z-[70] flex max-h-[calc(100dvh-2rem)] w-[calc(100vw-1.5rem)] max-w-[calc(100vw-1.5rem)] flex-col overflow-y-auto rounded-2xl border border-slate-700 bg-slate-950 text-white shadow-2xl shadow-black/50 sm:w-[22rem] sm:max-w-[22rem] ${position ? 'left-0 top-0' : 'inset-x-3 bottom-3 sm:inset-x-auto sm:bottom-5 sm:right-5'}`} aria-label={t('meetingRoom.stage.miniWindow')}>
        <header role="group" aria-label={t('meetingRoom.stage.moveMiniWindow')} tabIndex={0} onKeyDown={moveWithKeyboard} onPointerDown={startDrag} onPointerMove={moveDrag} onPointerUp={stopDrag} onPointerCancel={stopDrag} onLostPointerCapture={stopDrag} className={`flex shrink-0 select-none items-center justify-between gap-3 border-b border-white/10 bg-slate-900 px-3 py-2 touch-none ${dragging ? 'cursor-grabbing' : 'cursor-grab'}`}>
            <span className="min-w-0 truncate text-sm font-bold" title={meeting.title}>{meeting.title}</span>
            <ConnectionStatus notice={connectionError}/>
        </header>
        <button type="button" onClick={onReturn} className="relative block aspect-video w-full shrink-0 overflow-hidden bg-[radial-gradient(circle_at_50%_25%,#374151,#111827_70%)] text-left focus:outline-none focus:ring-2 focus:ring-inset focus:ring-violet-300" aria-label={t('meetingRoom.stage.returnToMeeting')}>
            {preview?.publication ? <VideoTrack trackRef={preview} className={`h-full w-full object-cover ${localCameraTrackClass(preview)}`}/> : <div className="flex h-full flex-col items-center justify-center gap-3 px-6 text-center text-slate-300"><MeetingParticipantAvatar participant={featuredParticipant} size="lg"/><p className="text-sm font-semibold">{t('meetingRoom.stage.stillRunning')}</p><p className="text-xs text-slate-400">{t('meetingRoom.stage.shareHint')}</p></div>}
            <div className="pointer-events-none absolute bottom-3 left-3 rounded-lg bg-black/60 px-2 py-1 text-xs font-bold text-white">{status}{elapsedTime && ` · ${elapsedTime}`}</div>
        </button>
        <div className="flex shrink-0 flex-wrap items-center justify-between gap-2 p-3">
            <div className="flex items-center gap-1.5" aria-label={t('meetingRoom.stage.deviceControls')}>
                <TrackToggle source={Track.Source.Camera} showIcon={false} disabled={!available || !mediaReady} onClick={() => writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: !isCameraEnabled})} onChange={(enabled, isUserInitiated) => { if (isUserInitiated) writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: enabled}); }} onDeviceError={() => { writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: false}); onMessage(noticeKey('meetingRoom.controlCenter.cameraEnableFailed')); }} className="rounded-xl border border-white/15 bg-white/10 p-2 text-white transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={t(isCameraEnabled ? 'meetingRoom.controlCenter.turnCameraOff' : 'meetingRoom.controlCenter.turnCameraOn')} aria-pressed={isCameraEnabled}><Icon name={isCameraEnabled ? 'video' : 'video-off'} className="h-5.5 w-5.5"/></TrackToggle>
                <TrackToggle source={Track.Source.Microphone} showIcon={false} disabled={!available || !mediaReady} onClick={() => writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: !isMicrophoneEnabled})} onChange={(enabled, isUserInitiated) => { if (isUserInitiated) writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: enabled}); }} onDeviceError={() => { writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: false}); onMessage(noticeKey('meetingRoom.controlCenter.micEnableFailed')); }} className="rounded-xl border border-white/15 bg-white/10 p-2 text-white transition hover:bg-white/20 focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={t(isMicrophoneEnabled ? 'meetingRoom.controlCenter.muteMicrophone' : 'meetingRoom.controlCenter.unmuteMicrophone')} aria-pressed={isMicrophoneEnabled}><Icon name={isMicrophoneEnabled ? 'mic' : 'mic-off'} className="h-5.5 w-5.5"/></TrackToggle>
            </div>
            <div className="flex items-center gap-2"><button type="button" onClick={onReturn} className="rounded-xl bg-violet-600 px-3 py-2 text-xs font-bold text-white transition hover:bg-violet-500 focus:outline-none focus:ring-2 focus:ring-violet-300">{t('meetingRoom.stage.returnToMeeting')}</button><button type="button" onClick={leave} disabled={leaving} className="rounded-xl border border-rose-400/40 bg-rose-600/15 px-3 py-2 text-xs font-bold text-rose-100 transition hover:bg-rose-600 focus:outline-none focus:ring-2 focus:ring-rose-300 disabled:cursor-wait disabled:opacity-60">{t(leaving ? 'meetingRoom.controlCenter.leaving' : 'meetingRoom.controlCenter.leave')}</button></div>
        </div>
        {hasNotice(mediaMessage) && <p className="px-3 pb-3 text-xs text-amber-200" role="status">{renderNotice(mediaMessage, t)}</p>}
        <p className="sr-only">{t('meetingRoom.stage.controlLabel', {className: schoolClass.name})}</p>
    </aside>;
}

function RoomContent({meeting, clock, schoolClass, initialMedia, mediaIntent, mediaIntentKey, recording, onLeave, onEnd, onReturn, mode, mediaMessage, onMediaMessage, connectionError, isFullscreen, exitFullscreen}) {
    const {t} = useTranslation();
    const [panel, setPanel] = useState(null);
    const [view, setView] = useState('gallery');
    const [copied, setCopied] = useState(null);
    const connection = useConnectionState();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled} = useLocalParticipant();
    const room = useRoomContext();
    const mediaRestoreStarted = useRef(false);
    const [mediaReady, setMediaReady] = useState(false);
    const participants = useParticipants();
    const signals = useMeetingEphemeralSignals();
    const elapsedTime = useMeetingElapsedTime(meeting, clock);
    const participantIdentities = useMemo(() => participants.map((participant) => participant.identity).sort().join(','), [participants]);
    const endMeeting = useCallback(async () => {
        // A terminal Echo event can begin its own navigation transaction before
        // the End response reaches this callback. Disconnect directly so the
        // initiating host never depends on that event's transaction.
        await completeConfirmedMeetingEnd({
            stopMedia: () => Promise.allSettled([
                localParticipant.setCameraEnabled(false),
                localParticipant.setMicrophoneEnabled(false),
                localParticipant.setScreenShareEnabled(false),
            ]),
            disconnectRoom: () => room.disconnect(),
            navigate: onEnd,
        });
    }, [localParticipant, onEnd, room]);
    const moderation = useMeetingModeration({meeting, schoolClass, connected: connection === 'connected', participantIdentities, onMeetingEnded: endMeeting});
    const screenShareApproval = useMeetingScreenShareApproval({meeting, schoolClass, connected: connection === 'connected', onNotice: onMediaMessage});
    const meetingLink = useMemo(() => authorizedMeetingLink(meeting, schoolClass, window.location.origin), [meeting, schoolClass]);
    const raisedCount = participants.filter((participant) => signals.raisedHands[participant.identity]).length;

    useEffect(() => {
        if (connection !== 'connected' || mediaRestoreStarted.current) return;
        mediaRestoreStarted.current = true;

        const restoreMedia = async () => {
            const desiredCamera = mediaIntent?.cameraEnabled ?? initialMedia.camera;
            const desiredMicrophone = mediaIntent?.microphoneEnabled ?? initialMedia.microphone;
            const cameraNeedsChange = isCameraEnabled !== desiredCamera;
            const microphoneNeedsChange = isMicrophoneEnabled !== desiredMicrophone;
            const [cameraResult, microphoneResult] = await Promise.allSettled([
                cameraNeedsChange ? localParticipant.setCameraEnabled(desiredCamera) : Promise.resolve(),
                microphoneNeedsChange ? localParticipant.setMicrophoneEnabled(desiredMicrophone) : Promise.resolve(),
            ]);

            writeMeetingMediaIntent(mediaIntentKey, {
                cameraEnabled: desiredCamera && cameraResult.status === 'fulfilled',
                microphoneEnabled: desiredMicrophone && microphoneResult.status === 'fulfilled',
            });

            const failedDevices = [];
            if (cameraNeedsChange && cameraResult.status === 'rejected') failedDevices.push('meetingRoom.controlCenter.camera');
            if (microphoneNeedsChange && microphoneResult.status === 'rejected') failedDevices.push('meetingRoom.controlCenter.mic');
            if (failedDevices.length) {
                // The device names and their separator are translated too, so they
                // are resolved lazily instead of being frozen into the params.
                onMediaMessage(noticeKey('meetingRoom.stage.restoreFailed', {
                    devices: (translate) => failedDevices.map((key) => translate(key)).join(translate('meetingRoom.stage.deviceSeparator')),
                }));
            }
            setMediaReady(true);
        };

        restoreMedia();
    }, [connection, initialMedia.camera, initialMedia.microphone, isCameraEnabled, isMicrophoneEnabled, localParticipant, mediaIntent, mediaIntentKey, onMediaMessage]);
    const copyMeetingLink = useCallback(async () => {
        if (!meetingLink || !navigator.clipboard?.writeText) {
            setCopied(noticeKey('meetingRoom.errors.copyUnavailable'));
            return;
        }
        try {
            await navigator.clipboard.writeText(meetingLink);
            setCopied(noticeKey('meetingRoom.errors.linkCopied'));
        } catch {
            setCopied(noticeKey('meetingRoom.errors.linkCopyFailed'));
        }
    }, [meetingLink]);

    if (mode === 'mini') return <><MeetingRoomSounds meeting={meeting}/><RoomAudioRenderer/><MiniMeetingWindow meeting={meeting} schoolClass={schoolClass} elapsedTime={elapsedTime} connectionError={connectionError} mediaMessage={mediaMessage} mediaIntentKey={mediaIntentKey} mediaReady={mediaReady} onMessage={onMediaMessage} onReturn={onReturn} onLeave={onLeave} isFullscreen={isFullscreen} exitFullscreen={exitFullscreen}/></>;

    return <><MeetingRoomSounds meeting={meeting}/><div className={`meeting-room-shell fixed z-10 flex min-h-0 flex-col bg-slate-950 ${isFullscreen ? 'inset-0 h-[100dvh] overflow-hidden p-0' : 'inset-x-0 bottom-0 top-14 overflow-y-auto p-3 sm:p-4 lg:left-56 lg:p-6'}`}>
        <div className={`flex min-h-0 w-full flex-1 flex-col overflow-y-auto xl:flex-row xl:overflow-hidden ${isFullscreen ? 'min-h-0' : 'min-h-[38rem]'}`}>
        <section className={`meeting-room-content relative flex min-h-[34rem] min-w-0 flex-1 flex-col overflow-hidden rounded-3xl border border-slate-800 bg-[radial-gradient(circle_at_18%_12%,rgba(104,91,224,.24),transparent_34%),linear-gradient(145deg,#171b28,#0a0d14_70%)] ${isFullscreen ? 'min-h-0 p-3 shadow-none' : 'p-3 shadow-[0_24px_64px_rgba(20,19,50,.32)]'}`}>
            <header className="relative z-10 flex shrink-0 flex-wrap items-start justify-between gap-3 rounded-2xl bg-black/20 px-3 py-2.5"><div className="min-w-0"><p className="flex items-center gap-2 text-sm font-bold uppercase tracking-wide text-white"><span className="grid h-9 w-9 place-items-center rounded-xl bg-white/10"><Icon name="video" className="h-5 w-5"/></span>{t('meetingRoom.stage.liveClass')}</p><p className="mt-1.5 truncate pl-11 text-sm font-medium text-slate-200">{schoolClass.name}{schoolClass.section ? ` · ${schoolClass.section}` : ''}{meeting.subject ? ` · ${meeting.subject.name}` : ''}</p><div className="mt-1.5 flex items-center gap-3 pl-11"><ConnectionStatus notice={connectionError}/><ElapsedTime value={elapsedTime}/></div></div><div className="flex items-center gap-2"><RoomSummary/><StartAudio label={t('meetingRoom.stage.enableAudio')} className="rounded-xl bg-sky-700 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-600"/></div></header>
            <ReactionOverlay events={signals.reactionEvents}/>
            {hasNotice(mediaMessage) || hasNotice(moderation.message) ? <div className="relative z-10 mt-3 shrink-0 rounded-xl border border-amber-300/25 bg-amber-300/10 px-4 py-3 text-sm text-amber-100" role="status">{renderFirstNotice([mediaMessage, moderation.message], t)}</div> : null}
            <div className="relative z-0 flex min-h-0 flex-1 flex-col py-3"><MeetingStage view={view} onViewChange={setView}/></div>
            <RoomAudioRenderer/>
            <MeetingControlCenter meeting={meeting} activePanel={panel} onPanelChange={setPanel} signals={signals} waitingCount={moderation.requests.length} screenShareRequestCount={moderation.screenShareRequests.length} screenShareApproval={screenShareApproval} raisedCount={raisedCount} view={view} onViewChange={setView} onCopyLink={copyMeetingLink} copied={copied} hasMeetingLink={Boolean(meetingLink)} mediaIntent={mediaIntent} mediaIntentKey={mediaIntentKey} mediaReady={mediaReady} onLeave={onLeave} onMessage={onMediaMessage} isFullscreen={isFullscreen} exitFullscreen={exitFullscreen} recording={recording}/>
        </section>
        {panel && <aside className="flex h-[70vh] min-h-[22rem] shrink-0 flex-col xl:h-full xl:min-h-0 xl:w-[clamp(17rem,24vw,22rem)] xl:pl-4">{panel === 'chat' ? <MeetingChatPanel messages={signals.messages} onClose={() => setPanel(null)} onSend={signals.sendMessage} connected={signals.connected} maxMessageLength={signals.maxMessageLength}/> : panel === 'people' ? <ParticipantsPanel meeting={meeting} onClose={() => setPanel(null)} raisedHands={signals.raisedHands} moderation={moderation}/> : panel === 'host' ? <HostControlsPanel meeting={meeting} moderation={moderation} onClose={() => setPanel(null)} onPeople={() => setPanel('people')} onInfo={() => setPanel('info')}/> : panel === 'info' ? <MeetingInfoPanel meeting={meeting} schoolClass={schoolClass} count={participants.length} meetingLink={meetingLink} onCopy={copyMeetingLink} copied={copied} onClose={() => setPanel(null)}/> : panel === 'devices' ? <MeetingDeviceSettings onClose={() => setPanel(null)} onMessage={onMediaMessage}/> : null}</aside>}
        </div>
    </div></>;
}

export default function MeetingRoomExperience({credentials, meeting, clock, schoolClass, initialMedia, mediaIntent, mediaIntentKey, recording, mode = 'full', onReturn, onLeave, onEnd}) {
    useMeetingNavigationGuard(meeting.status === 'active' && mode === 'full');
    const [connectionError, setConnectionError] = useState(null);
    const [mediaMessage, setMediaMessage] = useState(null);
    const {isFullscreen, exitFullscreen} = useMeetingFullscreen();
    const {setIsFullscreen, setIsMeetingRoom} = useFullscreenContext();

    const audio = initialMedia.microphone ? (initialMedia.microphoneId ? {deviceId: initialMedia.microphoneId} : true) : false;
    const video = initialMedia.camera ? (initialMedia.cameraId ? {deviceId: initialMedia.cameraId} : true) : false;

    // Sync fullscreen state with global context
    useEffect(() => {
        setIsFullscreen(isFullscreen);
        setIsMeetingRoom(true);
    }, [isFullscreen, setIsFullscreen, setIsMeetingRoom]);

    // Clean up on unmount
    useEffect(() => {
        return () => {
            setIsMeetingRoom(false);
        };
    }, [setIsMeetingRoom]);

    // Exit fullscreen when user explicitly leaves the meeting
    const exitMeetingFullscreen = useCallback(async () => {
        try {
            // The browser is authoritative here: React state can lag a native
            // fullscreen change during teardown.
            await exitFullscreen();
        } finally {
            setIsFullscreen(false);
        }
    }, [exitFullscreen, setIsFullscreen]);

    const handleLeave = useCallback(async (cleanup) => {
        await exitMeetingFullscreen();
        await onLeave(cleanup);
    }, [exitMeetingFullscreen, onLeave]);

    const handleEnd = useCallback(async () => {
        await exitMeetingFullscreen();
        await onEnd();
    }, [exitMeetingFullscreen, onEnd]);

    // Exit fullscreen when meeting ends, is ending, or is cancelled
    useEffect(() => {
        if (['ending', 'ended', 'cancelled'].includes(meeting.status)) {
            exitMeetingFullscreen();
        }
    }, [meeting.status, exitMeetingFullscreen]);

    return <LiveKitRoom token={credentials.token} serverUrl={credentials.server_url} connect audio={audio} video={video}
        onConnected={() => setConnectionError(null)}
        onError={() => setConnectionError(noticeKey('meetingRoom.errors.roomJoinFailed'))}
        onDisconnected={() => setConnectionError(noticeKey('meetingRoom.errors.interrupted'))}
        className="edway-live-room overscroll-x-none bg-transparent text-white">
        <RoomContent meeting={meeting} clock={clock} schoolClass={schoolClass} recording={recording} initialMedia={initialMedia} mediaIntent={mediaIntent} mediaIntentKey={mediaIntentKey} onLeave={handleLeave} onEnd={handleEnd} onReturn={onReturn} mode={mode} mediaMessage={mediaMessage} onMediaMessage={setMediaMessage} connectionError={connectionError} isFullscreen={isFullscreen} exitFullscreen={exitFullscreen}/>
    </LiveKitRoom>;
}

import React, {useEffect, useRef, useState} from 'react';
import {LiveKitRoom, RoomAudioRenderer, StartAudio, useConnectionState, useLocalParticipant, useParticipants, useRoomContext, useTracks, VideoTrack} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../UI/Icon';
import UserAvatar from '../UI/UserAvatar';
import {
    clampMiniWindowPosition,
    dragMiniWindowPosition,
    shouldStartMiniWindowDrag,
} from '../Meetings/LiveKit/meetingMiniWindowPosition';
import {conversationCallDurationSeconds, conversationCallMediaOutcome, conversationCallMediaPlan, writeConversationCallMediaIntent} from './conversationCallMediaIntent';

const formatDuration = (totalSeconds) => {
    const diff = totalSeconds;
    const hours = String(Math.floor(diff / 3600)).padStart(2, '0');
    const minutes = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
    const seconds = String(diff % 60).padStart(2, '0');

    return hours === '00' ? `${minutes}:${seconds}` : `${hours}:${minutes}:${seconds}`;
};

function useCallTimer(startedAt, serverClock) {
    const [now, setNow] = useState(() => performance.now());

    React.useEffect(() => {
        const timer = window.setInterval(() => setNow(performance.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    return formatDuration(conversationCallDurationSeconds(startedAt, now, serverClock));
}

function MiniConversationCallWindow({call, mode, onReturn, onLeave, mediaMessage, cameraUnavailable, microphoneUnavailable, serverClock}) {
    const panelRef = useRef(null);
    const dragRef = useRef(null);
    const [position, setPosition] = useState(null);
    const [dragging, setDragging] = useState(false);
    const [leaving, setLeaving] = useState(false);
    const timer = useCallTimer(call.started_at, serverClock);
    const {localParticipant, isMicrophoneEnabled, isCameraEnabled} = useLocalParticipant();
    const connection = useConnectionState();
    const cameraTracks = useTracks([{source: Track.Source.Camera, withPlaceholder: false}]);
    const remoteCameraTrack = cameraTracks.find((track) => !track.participant.isLocal);

    const clampToViewport = React.useCallback(() => {
        const panel = panelRef.current;
        if (!panel) return;
        const bounds = panel.getBoundingClientRect();
        setPosition((current) => clampMiniWindowPosition(current || {left: bounds.left, top: bounds.top}, {width: bounds.width, height: bounds.height}, {width: window.innerWidth, height: window.innerHeight}));
    }, []);

    React.useEffect(() => {
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

        setPosition(dragMiniWindowPosition({
            origin: drag.origin,
            pointerStart: drag.pointerStart,
            pointer: {x: event.clientX, y: event.clientY},
            size: {width: panel.getBoundingClientRect().width, height: panel.getBoundingClientRect().height},
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
        if (leaving) return;
        setLeaving(true);
        try {
            await Promise.allSettled([
                localParticipant?.setMicrophoneEnabled(false),
                ...(call.type === 'video' ? [localParticipant?.setCameraEnabled(false)] : []),
            ]);
            await onLeave?.();
        } finally {
            setLeaving(false);
        }
    };

    return <aside ref={panelRef} style={position ? {left: position.left, top: position.top} : undefined} className={`fixed z-[85] w-[calc(100vw-1.5rem)] max-w-[22rem] overflow-hidden rounded-2xl border border-slate-700 bg-slate-950 text-white shadow-2xl shadow-black/40 ${position ? 'left-0 top-0' : 'bottom-4 right-4'}`} aria-label="Mini call window">
        <header role="group" aria-label="Move call window" tabIndex={0} onKeyDown={moveWithKeyboard} onPointerDown={startDrag} onPointerMove={moveDrag} onPointerUp={stopDrag} onPointerCancel={stopDrag} onLostPointerCapture={stopDrag} className={`flex select-none items-center justify-between gap-3 border-b border-white/10 bg-slate-900 px-3 py-2 touch-none ${dragging ? 'cursor-grabbing' : 'cursor-grab'}`}>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-white">{call.name}</p>
                <p className="text-[10px] uppercase tracking-[0.2em] text-slate-400">{call.type === 'video' ? 'Video call' : 'Audio call'}</p>
            </div>
            <span className="rounded-full bg-white/10 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-slate-200">{connectionLabel(connection)} · {timer}</span>
        </header>
        <button type="button" onClick={onReturn} className="relative block aspect-video w-full overflow-hidden bg-[radial-gradient(circle_at_50%_20%,#334155,#0f172a_72%)] text-left focus:outline-none focus:ring-2 focus:ring-sky-400" aria-label="Return to call">
            {call.type === 'video' && remoteCameraTrack?.publication ? <VideoTrack trackRef={remoteCameraTrack} className="h-full w-full object-cover"/> : <div className="flex h-full flex-col items-center justify-center gap-3 px-5 text-center text-slate-200"><UserAvatar name={remoteDisplayName(call, remoteCameraTrack?.participant)} avatarUrl={remoteAvatar(call, remoteCameraTrack?.participant)} size="lg" className="border-0 shadow-lg shadow-slate-900/30"/><p className="max-w-[12rem] text-sm font-semibold">{remoteDisplayName(call, remoteCameraTrack?.participant)}</p></div>}
            <div className="pointer-events-none absolute inset-x-3 bottom-3 flex items-center justify-between gap-3"><span className="rounded-lg bg-black/60 px-2 py-1 text-[11px] font-bold text-white">{mode === 'mini' ? 'Mini view' : 'Full view'}</span><span className="rounded-lg bg-black/60 px-2 py-1 text-[11px] font-bold text-white">{timer}</span></div>
        </button>
        <div className="flex items-center justify-between gap-2 p-3">
            <div className="flex items-center gap-2">
                <TrackButton source={Track.Source.Microphone} enabled={isMicrophoneEnabled} unavailable={microphoneUnavailable} localParticipant={localParticipant} onFailure={() => {setMicrophoneUnavailable(true); setMediaMessage('Microphone access is blocked. Allow microphone access and select Unmute to retry.');}} label={isMicrophoneEnabled ? 'Mute microphone' : 'Unmute microphone'}/>
                {call.type === 'video' && <TrackButton source={Track.Source.Camera} enabled={isCameraEnabled} unavailable={cameraUnavailable} localParticipant={localParticipant} onFailure={() => {setCameraUnavailable(true); setMediaMessage('Camera unavailable. The audio call continues; allow camera access and select Camera On to retry.');}} label={isCameraEnabled ? 'Turn camera off' : 'Turn camera on'}/>}
            </div>
            <div className="flex items-center gap-2">
                <button type="button" onClick={onReturn} className="rounded-xl bg-sky-600 px-3 py-2 text-[11px] font-bold text-white">Return</button>
                <button type="button" onClick={leave} disabled={leaving} className="rounded-xl border border-rose-400/40 bg-rose-600/15 px-3 py-2 text-[11px] font-bold text-rose-100 disabled:opacity-60">{leaving ? 'Ending' : 'End'}</button>
            </div>
        </div>
        {mediaMessage && <p role="status" className="border-t border-white/10 px-3 py-2 text-xs text-amber-200">{mediaMessage}</p>}
    </aside>;
}

function FullConversationCallOverlay({call, onModeChange, onLeave, mediaMessage, cameraUnavailable, microphoneUnavailable, serverClock}) {
    const timer = useCallTimer(call.started_at, serverClock);
    const {localParticipant, isMicrophoneEnabled, isCameraEnabled} = useLocalParticipant();
    const connection = useConnectionState();
    const participants = useParticipants();
    const cameraTracks = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);
    const remoteTrack = cameraTracks.find((track) => !track.participant.isLocal);
    const localTrack = cameraTracks.find((track) => track.participant.isLocal);
    const otherParticipant = remoteTrack?.participant || participants.find((participant) => !participant.isLocal);
    const remoteName = remoteDisplayName(call, otherParticipant);
    const canShowCamera = call.type === 'video';

    return <div className="fixed inset-0 z-[80] bg-slate-950/80 px-4 py-5 backdrop-blur-sm sm:px-6 lg:px-8">
        <div className="mx-auto flex h-full max-w-6xl flex-col rounded-[28px] border border-slate-700 bg-slate-950/95 text-white shadow-2xl shadow-slate-950/60">
            <header className="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                <div className="min-w-0">
                    <p className="text-[11px] font-bold uppercase tracking-[0.28em] text-sky-300">{call.type === 'video' ? 'Video call' : 'Audio call'}</p>
                    <h2 className="mt-1 truncate text-xl font-bold text-white sm:text-2xl">{call.name}</h2>
                </div>
                <div className="flex items-center gap-2">
                    <span role="status" className={`rounded-full border px-3 py-1 text-xs font-bold ${connection === 'connected' ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-200' : 'border-amber-500/30 bg-amber-500/10 text-amber-200'}`}>{connectionLabel(connection)}</span>
                    <span className="rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-bold text-slate-100">{timer}</span>
                    <button type="button" onClick={() => onModeChange('mini')} className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold text-white">Mini view</button>
                </div>
            </header>

            <div className="grid flex-1 gap-4 p-4 sm:p-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(16rem,0.7fr)]">
                <section className="relative overflow-hidden rounded-[24px] border border-slate-700 bg-[radial-gradient(circle_at_50%_25%,#475569,#0f172a_72%)]">
                    {canShowCamera && remoteTrack?.publication ? <VideoTrack trackRef={remoteTrack} className="h-full w-full object-cover"/> : <div className="flex h-full min-h-[20rem] flex-col items-center justify-center gap-4 px-6 text-center"><UserAvatar name={remoteName} avatarUrl={remoteAvatar(call, otherParticipant)} size="xl" className="border-0 shadow-2xl shadow-slate-950/60"/><p className="text-lg font-semibold text-slate-200">{remoteName}</p><p className="text-sm text-slate-300">{canShowCamera ? 'Camera is off' : 'Audio-only call'}</p></div>}
                    <div className="absolute inset-x-0 bottom-0 flex items-center justify-between p-4">
                        <div className="rounded-full bg-slate-950/70 px-3 py-1 text-xs font-bold text-slate-100">{remoteName}</div>
                        <div className="rounded-full bg-slate-950/70 px-3 py-1 text-xs font-bold text-slate-100">{timer}</div>
                    </div>
                </section>

                <aside className="flex flex-col gap-4">
                    <div className="rounded-[24px] border border-slate-700 bg-slate-900/80 p-4">
                        <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-slate-400">You</p>
                        <div className="mt-4 overflow-hidden rounded-2xl border border-slate-700 bg-[radial-gradient(circle_at_50%_25%,#374151,#111827)]">
                                    {canShowCamera && isCameraEnabled && localTrack ? <div className="h-44 w-full"><VideoTrack trackRef={localTrack} className="h-full w-full object-cover"/></div> : <div className="flex h-44 flex-col items-center justify-center gap-3 px-4 text-center"><UserAvatar name="You" size="lg" className="border-0 shadow-lg shadow-slate-900/30"/><p className="text-sm font-semibold text-slate-200">{cameraUnavailable ? 'Camera unavailable' : canShowCamera ? 'Camera is off' : 'Audio-only call'}</p></div>}
                        </div>
                    </div>

                    <div className="rounded-[24px] border border-slate-700 bg-slate-900/80 p-4">
                        <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-slate-400">Controls</p>
                        <div className="mt-4 flex flex-wrap items-center gap-3">
                            <TrackButton source={Track.Source.Microphone} enabled={isMicrophoneEnabled} unavailable={microphoneUnavailable} localParticipant={localParticipant} onFailure={() => {setMicrophoneUnavailable(true); setMediaMessage('Microphone access is blocked. Allow microphone access and select Unmute to retry.');}} label={isMicrophoneEnabled ? 'Mute microphone' : 'Unmute microphone'}/>
                            {canShowCamera && <TrackButton source={Track.Source.Camera} enabled={isCameraEnabled} unavailable={cameraUnavailable} localParticipant={localParticipant} onFailure={() => {setCameraUnavailable(true); setMediaMessage('Camera unavailable. The audio call continues; allow camera access and select Camera On to retry.');}} label={isCameraEnabled ? 'Turn camera off' : 'Turn camera on'}/>}
                            <button type="button" onClick={() => onModeChange('mini')} className="rounded-xl border border-white/15 bg-white/10 px-4 py-3 text-sm font-bold text-white">Mini</button>
                            <button type="button" onClick={onLeave} className="ml-auto rounded-xl bg-rose-600 px-4 py-3 text-sm font-bold text-white">End call</button>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
        {mediaMessage && <p role="status" className="absolute bottom-4 left-1/2 max-w-lg -translate-x-1/2 rounded-xl border border-amber-300/30 bg-slate-950 px-4 py-3 text-sm text-amber-100 shadow-xl">{mediaMessage}</p>}
    </div>;
}

function connectionLabel(connection) {
    if (connection === 'connected') return 'Connected';
    if (connection === 'reconnecting' || connection === 'signalReconnecting') return 'Reconnecting';
    if (connection === 'disconnected') return 'Disconnected';
    return 'Connecting';
}

function remoteDisplayName(call, participant) {
    const identityId = participant?.identity?.split(':').at(-1);
    const known = call.participants?.find((person) => String(person.id) === identityId);
    return participant?.name || known?.name || call.initiator?.name || 'Participant';
}

function remoteAvatar(call, participant) {
    const identityId = participant?.identity?.split(':').at(-1);
    return call.participants?.find((person) => String(person.id) === identityId)?.avatar_url || call.initiator?.avatar_url;
}

function TrackButton({source, enabled, unavailable, localParticipant, onFailure, label}) {
    const [busy, setBusy] = useState(false);
    const icon = source === Track.Source.Camera ? (enabled ? 'video' : 'video-off') : (enabled ? 'mic' : 'mic-off');

    const toggle = async () => {
        if (busy) return;
        setBusy(true);
        try {
            if (source === Track.Source.Camera) await localParticipant.setCameraEnabled(!enabled);
            else await localParticipant.setMicrophoneEnabled(!enabled);
        } catch {
            onFailure?.();
        } finally {
            setBusy(false);
        }
    };

    return <button type="button" onClick={toggle} disabled={busy} aria-label={label} aria-pressed={enabled} title={unavailable ? `${label} (permission required)` : label} className="rounded-xl border border-white/15 bg-white/10 p-2 text-white disabled:opacity-50"><Icon name={icon} className="h-5 w-5"/></button>;
}

function ActiveConversationCall({call, mode, audioOnly, mediaIntent, mediaIntentKey, serverClock, onLeave, onModeChange}) {
    const connection = useConnectionState();
    const {localParticipant, isMicrophoneEnabled, isCameraEnabled} = useLocalParticipant();
    const room = useRoomContext();
    const mediaRestoreStarted = useRef(false);
    const [cameraUnavailable, setCameraUnavailable] = useState(false);
    const [microphoneUnavailable, setMicrophoneUnavailable] = useState(false);
    const [mediaMessage, setMediaMessage] = useState('');
    const mediaPlan = conversationCallMediaPlan({callType: call.type, audioOnly, mediaIntent});

    useEffect(() => {
        if (connection !== 'connected' || mediaRestoreStarted.current) return;
        mediaRestoreStarted.current = true;
        const desiredMicrophone = mediaPlan.microphoneEnabled;
        const desiredCamera = mediaPlan.cameraEnabled;

        Promise.allSettled([
            localParticipant.setMicrophoneEnabled(desiredMicrophone),
            ...(desiredCamera ? [localParticipant.setCameraEnabled(true)] : []),
        ]).then((results) => {
            const microphoneResult = results[0];
            const cameraResult = desiredCamera ? results[1] : null;
            const outcome = conversationCallMediaOutcome({microphoneStatus: microphoneResult.status, cameraStatus: cameraResult?.status});
            setMicrophoneUnavailable(outcome.microphoneUnavailable);
            setCameraUnavailable(outcome.cameraUnavailable);
            writeConversationCallMediaIntent(mediaIntentKey, {
                microphoneEnabled: desiredMicrophone && microphoneResult.status === 'fulfilled',
                ...(call.type === 'video' ? {cameraEnabled: desiredCamera && cameraResult?.status === 'fulfilled'} : {}),
            });
            setMediaMessage(outcome.message);
        });
    }, [call.type, connection, localParticipant, mediaIntent, mediaIntentKey, mediaPlan]);

    useEffect(() => {
        writeConversationCallMediaIntent(mediaIntentKey, {
            microphoneEnabled: isMicrophoneEnabled,
            ...(call.type === 'video' ? {cameraEnabled: isCameraEnabled} : {}),
        });
    }, [call.type, isCameraEnabled, isMicrophoneEnabled, mediaIntentKey]);

    useEffect(() => {
        if (isMicrophoneEnabled) {
            setMicrophoneUnavailable(false);
            if (!cameraUnavailable) setMediaMessage('');
        }
        if (isCameraEnabled) {
            setCameraUnavailable(false);
            setMediaMessage('');
        }
    }, [isCameraEnabled, isMicrophoneEnabled]);

    const leave = async () => {
        await Promise.allSettled([
            localParticipant.setMicrophoneEnabled(false),
            ...(call.type === 'video' ? [localParticipant.setCameraEnabled(false)] : []),
        ]);
        await onLeave?.(() => room.disconnect());
    };

    const overlayProps = {call, mode, onModeChange, onLeave: leave, mediaMessage, cameraUnavailable, microphoneUnavailable, serverClock};
    return <>
        {mode === 'mini'
            ? <MiniConversationCallWindow {...overlayProps} onReturn={() => onModeChange?.('full')}/>
            : <FullConversationCallOverlay {...overlayProps}/>}
        <RoomAudioRenderer/>
        <StartAudio label="Enable call audio"/>
    </>;
}

export default function ConversationCallExperience({call, credentials, mode = 'full', audioOnly = false, mediaIntent, mediaIntentKey, serverClock, onLeave, onModeChange}) {
    if (!call?.uuid || !credentials?.token || !credentials?.server_url) return null;

    return <LiveKitRoom token={credentials.token} serverUrl={credentials.server_url} connect audio={false} video={false} className="bg-transparent text-white" onDisconnected={() => null} onError={() => null}>
        <ActiveConversationCall call={call} mode={mode} audioOnly={audioOnly} mediaIntent={mediaIntent} mediaIntentKey={mediaIntentKey} serverClock={serverClock} onLeave={onLeave} onModeChange={onModeChange}/>
    </LiveKitRoom>;
}

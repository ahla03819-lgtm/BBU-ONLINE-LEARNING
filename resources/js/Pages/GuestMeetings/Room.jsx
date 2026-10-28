import React, {useEffect, useState} from 'react';
import {Head, router} from '@inertiajs/react';
import {GridLayout, LiveKitRoom, ParticipantTile, RoomAudioRenderer, useLocalParticipant, useTracks} from '@livekit/components-react';
import {Track} from 'livekit-client';
import {useTranslation} from '../../i18n/LocaleProvider';
import {guestAccessTerminalStatuses, startGuestStatusPolling} from './guestWaitingRoomPolling';

function GuestMeetingControls({onLeave}) {
    const {t} = useTranslation();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled} = useLocalParticipant();
    const [mediaError, setMediaError] = useState(false);
    const [leaving, setLeaving] = useState(false);

    const toggle = async (kind, enabled) => {
        setMediaError(false);
        try {
            if (kind === 'camera') await localParticipant.setCameraEnabled(!enabled);
            else await localParticipant.setMicrophoneEnabled(!enabled);
        } catch {
            setMediaError(true);
        }
    };

    return <>
        {mediaError && <p className="mt-3 text-center text-sm text-rose-200" role="alert">{t('guestMeeting.room.mediaError')}</p>}
        <div className="mt-4 flex flex-wrap justify-center gap-3" aria-label={t('guestMeeting.room.guestAccess')}>
            <button type="button" className="rounded-xl border border-white/20 bg-slate-800 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-300" aria-pressed={isCameraEnabled} onClick={() => toggle('camera', isCameraEnabled)}>{isCameraEnabled ? t('guestMeeting.room.cameraOff') : t('guestMeeting.room.cameraOn')}</button>
            <button type="button" className="rounded-xl border border-white/20 bg-slate-800 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-300" aria-pressed={isMicrophoneEnabled} onClick={() => toggle('microphone', isMicrophoneEnabled)}>{isMicrophoneEnabled ? t('guestMeeting.room.microphoneOff') : t('guestMeeting.room.microphoneOn')}</button>
            <button type="button" className="rounded-xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white hover:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-200" disabled={leaving} onClick={() => { setLeaving(true); onLeave(); }}>{leaving ? t('guestMeeting.room.leaving') : t('guestMeeting.room.leave')}</button>
        </div>
    </>;
}

function GuestMeetingStage({onLeave}) {
    const {t} = useTranslation();
    const cameraTracks = useTracks([{source: Track.Source.Camera, withPlaceholder: true}]);

    return <div className="flex min-h-0 flex-1 flex-col">
        <div className="min-h-0 flex-1 overflow-hidden rounded-2xl border border-white/10 bg-slate-900 p-3">
            {cameraTracks.length > 0 ? <GridLayout tracks={cameraTracks} className="h-full"><ParticipantTile/></GridLayout> : <p className="flex h-full items-center justify-center text-sm text-slate-300">{t('guestMeeting.room.noParticipants')}</p>}
        </div>
        <RoomAudioRenderer/>
        <GuestMeetingControls onLeave={onLeave}/>
    </div>;
}

export default function GuestMeetingRoom({session, meeting, token_url: tokenUrl, waiting_url: waitingUrl, status_url: statusUrl, leave_url: leaveUrl, poll_interval_ms: pollIntervalMs}) {
    const {t} = useTranslation();
    const [credentials, setCredentials] = useState(null);
    const [error, setError] = useState(false);
    const [leaving, setLeaving] = useState(false);

    const terminal = (nextSession) => {
        if (guestAccessTerminalStatuses.has(nextSession.status) && nextSession.terminal_url) {
            window.location.replace(nextSession.terminal_url);
            return true;
        }
        return false;
    };

    const status = async () => {
        const response = await fetch(statusUrl, {headers: {Accept: 'application/json'}});
        if (!response.ok) throw new Error('Guest status unavailable');
        const data = await response.json();
        terminal(data.session);
        return data.session;
    };

    useEffect(() => {
        let cancelled = false;
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        fetch(tokenUrl, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}})
            .then(async (response) => {
                if (!response.ok) throw new Error('Guest token denied');
                return response.json();
            })
            .then((data) => { if (!cancelled) setCredentials({token: data.token, serverUrl: data.server_url}); })
            .catch(() => { if (!cancelled) setError(true); });
        return () => { cancelled = true; };
    }, [tokenUrl]);

    useEffect(() => startGuestStatusPolling({
        intervalMs: pollIntervalMs,
        terminalStatuses: guestAccessTerminalStatuses,
        fetchStatus: status,
        onStatus: terminal,
        onUnavailable: () => setError(true),
    }), [pollIntervalMs, statusUrl]);

    const leave = async () => {
        if (leaving) return;
        setLeaving(true);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(leaveUrl, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok || !data.redirect_url) throw new Error('Guest leave denied');
            window.location.replace(data.redirect_url);
        } catch {
            setError(true);
            setLeaving(false);
        }
    };

    const disconnected = async () => {
        try {
            const current = await status();
            if (!guestAccessTerminalStatuses.has(current.status)) setError(true);
        } catch {
            setError(true);
        }
    };

    return <><Head title={t('guestMeeting.room.title')}/><main className="flex min-h-screen flex-col bg-slate-950 px-3 py-4 text-white sm:px-6 sm:py-6"><header className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 pb-4"><div><p className="text-xs font-bold uppercase tracking-[.2em] text-indigo-300">{t('guestMeeting.brand')}</p><h1 className="mt-1 text-xl font-bold sm:text-2xl">{meeting.title || t('guestMeeting.room.title')}</h1><p className="mt-1 text-sm text-slate-300">{session.display_name} · {t('guestMeeting.room.guestAccess')}</p></div><p className="hidden rounded-full border border-emerald-400/30 bg-emerald-400/10 px-3 py-1 text-xs font-semibold text-emerald-200 sm:block">{t('guestMeeting.room.connected')}</p></header><section className="mx-auto flex w-full max-w-6xl min-h-0 flex-1 flex-col">{error ? <div className="m-auto max-w-lg rounded-2xl border border-rose-300/30 bg-rose-950/40 p-6 text-center"><p className="text-sm leading-6 text-rose-100" role="alert">{t('guestMeeting.room.connectionFailed')}</p><button type="button" className="mt-5 rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-900" onClick={() => router.visit(waitingUrl)}>{t('guestMeeting.waiting.title')}</button></div> : !credentials ? <p className="m-auto text-sm text-slate-200" role="status">{t('guestMeeting.room.connecting')}</p> : <LiveKitRoom serverUrl={credentials.serverUrl} token={credentials.token} connect audio={false} video={false} screen={false} onDisconnected={disconnected} onError={() => setError(true)} className="flex min-h-[65vh] flex-1 flex-col"><GuestMeetingStage onLeave={leave}/></LiveKitRoom>}</section></main></>;
}

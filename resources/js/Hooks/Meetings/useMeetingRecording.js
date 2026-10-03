import {useCallback, useEffect, useRef, useState} from 'react';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function send(url, {method = 'POST', body} = {}) {
    const response = await fetch(url, {
        method,
        headers: {
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            Accept: 'application/json',
            ...(body === undefined ? {} : {'Content-Type': 'application/json'}),
        },
        ...(body === undefined ? {} : {body: JSON.stringify(body)}),
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        throw new Error(payload.message || payload.errors?.meeting?.[0] || 'Request failed.');
    }

    return response.json();
}

/**
 * The teacher's recording controls and the state every participant sees.
 *
 * Three delivery paths feed one piece of state, in priority order:
 *
 *  1. the room page, so the first paint is already correct and no participant ever
 *     briefly sees an unrecorded room that is being recorded;
 *  2. the existing meetings.class realtime channel, so a start or a stop appears
 *     immediately for everyone in the class;
 *  3. a bounded poll, so a client whose websocket dropped, or that was hidden while
 *     the event fired, still converges on the server's truth.
 *
 * Every path applies the same authoritative projection from the server, and the
 * countdown is derived from the stored deadline inside that projection, so no path
 * can make the browser disagree with the server about what the recording is doing.
 */
export function useMeetingRecording({meeting, initialRecording, recordingUrl, leaveUrl, minDurationMinutes, maxDurationMinutes, onMessage}) {
    const [recording, setRecording] = useState(initialRecording ?? null);
    const [requesting, setRequesting] = useState(false);
    const [stopping, setStopping] = useState(false);
    const [dialog, setDialog] = useState(null);
    // Bumped on every open so a reopened dialog can reset its own fields even when
    // it is the same dialog it was last time.
    const [dialogToken, setDialogToken] = useState(0);
    const mounted = useRef(true);
    const [clock, setClock] = useState(() => ({serverNowAt: initialRecording?.server_now_at, receivedAt: performance.now()}));

    useEffect(() => () => {
        mounted.current = false;
    }, []);

    const adopt = useCallback((payload) => {
        if (!mounted.current || !payload) return;
        if (payload.server_now_at) {
            // Anchor the countdown on the server clock that accompanied this state,
            // so remaining time is derived from server time and not the local one.
            // It is state, not a ref, because the badge re-renders against it.
            setClock({serverNowAt: payload.server_now_at, receivedAt: performance.now()});
        }
        setRecording(payload.recording ?? null);
    }, []);

    useEffect(() => {
        setClock({serverNowAt: initialRecording?.server_now_at, receivedAt: performance.now()});
    }, [initialRecording?.server_now_at]);

    const refresh = useCallback(async () => {
        try {
            adopt(await send(`${recordingUrl}/current`, {method: 'GET'}));
        } catch {
            // A failed poll is not a verdict about the recording: the next tick and
            // any realtime event will correct it, so the current state is kept.
        }
    }, [adopt, recordingUrl]);

    useEffect(() => {
        if (!recordingUrl) return undefined;
        const timer = setInterval(refresh, 15000);

        return () => clearInterval(timer);
    }, [recordingUrl, refresh]);

    const closeDialog = useCallback(() => setDialog(null), []);

    const openDialog = useCallback((name) => {
        setDialogToken((token) => token + 1);
        setDialog(name);
    }, []);

    const start = useCallback(async (durationMinutes) => {
        if (requesting) return;
        setDialog(null);
        setRequesting(true);
        try {
            adopt(await send(recordingUrl, {body: {duration_minutes: durationMinutes}}));
        } catch (error) {
            onMessage?.(error.message);
            await refresh();
        } finally {
            if (mounted.current) setRequesting(false);
        }
    }, [adopt, onMessage, recordingUrl, refresh, requesting]);

    const stop = useCallback(async () => {
        if (stopping) return;
        setDialog(null);
        setStopping(true);
        try {
            adopt(await send(`${recordingUrl}/stop`));
        } catch (error) {
            onMessage?.(error.message);
            await refresh();
        } finally {
            if (mounted.current) setStopping(false);
        }
    }, [adopt, onMessage, recordingUrl, refresh, stopping]);

    /**
     * Tell the server this person explicitly left.
     *
     * Fire and forget by design. It exists so a recording this teacher started can
     * be closed, and it must never be able to delay or fail the local leave, which
     * is what keeps refresh and reconnect behaviour untouched.
     */
    const notifyExplicitLeave = useCallback(async () => {
        if (!leaveUrl) return;
        try {
            await send(leaveUrl);
        } catch {
            // Leaving must succeed locally regardless of what the server reports.
        }
    }, [leaveUrl]);

    return {
        state: recording,
        clock,
        requesting,
        stopping,
        dialog,
        dialogToken,
        openStart: () => openDialog('start'),
        openStop: () => openDialog('stop'),
        closeDialog,
        canStart: Boolean(meeting?.can_start_recording) && !recording,
        showStop: Boolean(recording?.can_stop),
        minDurationMinutes,
        maxDurationMinutes,
        start,
        stop,
        refresh,
        applyBroadcast: adopt,
        notifyExplicitLeave,
    };
}

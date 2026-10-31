export const LOBBY_WAITING_ROOM_POLL_INTERVAL = 5000;
const MAX_RETRY_DELAY = 30000;

export function canPollLobbyWaitingRoom(meeting) {
    return meeting.status === 'active' && meeting.can_manage_join_requests === true;
}

/**
 * Poll one policy-protected waiting-room list at a time. Authorization failures
 * end the current lobby's polling lifecycle; transient failures back off without
 * creating parallel requests or leaving a timer behind after unmount.
 */
export function startLobbyWaitingRoomPolling({
    url,
    onRequests,
    onAuthorizationFailure,
    onSessionFailure,
    onTemporaryFailure,
    fetchImpl = fetch,
    interval = LOBBY_WAITING_ROOM_POLL_INTERVAL,
    setTimeoutImpl = window.setTimeout,
    clearTimeoutImpl = window.clearTimeout,
}) {
    let disposed = false;
    let inFlight = false;
    let timer = null;
    let controller = null;
    let revision = 0;
    let consecutiveFailures = 0;

    const schedule = (delay) => {
        if (!disposed) timer = setTimeoutImpl(refresh, delay);
    };

    const refresh = async () => {
        if (disposed || inFlight) return;

        inFlight = true;
        const requestRevision = ++revision;
        controller = new AbortController();

        try {
            const response = await fetchImpl(url, {headers: {Accept: 'application/json'}, signal: controller.signal});

            if (disposed || controller.signal.aborted || requestRevision !== revision) return;

            if (response.status === 401) {
                disposed = true;
                onSessionFailure();
                return;
            }

            if (response.status === 403) {
                disposed = true;
                onAuthorizationFailure();
                return;
            }

            if (!response.ok) throw new Error('waiting-room-poll-failed');

            const data = await response.json();
            if (disposed || controller.signal.aborted || requestRevision !== revision) return;

            consecutiveFailures = 0;
            onRequests(data);
        } catch (error) {
            if (disposed || controller?.signal.aborted || requestRevision !== revision) return;

            consecutiveFailures += 1;
            onTemporaryFailure();
        } finally {
            inFlight = false;
            controller = null;

            if (!disposed) {
                const delay = consecutiveFailures === 0
                    ? interval
                    : Math.min(MAX_RETRY_DELAY, interval * (2 ** (consecutiveFailures - 1)));
                schedule(delay);
            }
        }
    };

    void refresh();

    return () => {
        disposed = true;
        revision += 1;
        if (timer !== null) clearTimeoutImpl(timer);
        controller?.abort();
    };
}

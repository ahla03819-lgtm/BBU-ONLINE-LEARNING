export const guestTerminalStatuses = new Set(['admitted', 'rejected', 'removed', 'expired', 'unavailable', 'meeting_ended']);
export const guestAccessTerminalStatuses = new Set(['rejected', 'removed', 'expired', 'unavailable', 'meeting_ended']);

export function startGuestStatusPolling({fetchStatus, onStatus, onUnavailable, intervalMs = 5000, maxFailures = 3, terminalStatuses = guestTerminalStatuses}) {
    let stopped = false;
    let inFlight = false;
    let failures = 0;
    let timer = null;

    const schedule = () => {
        if (!stopped) timer = setTimeout(poll, intervalMs);
    };
    const poll = async () => {
        if (stopped || inFlight) return;
        inFlight = true;
        try {
            const session = await fetchStatus();
            failures = 0;
            onStatus(session);
            if (!terminalStatuses.has(session.status)) schedule();
        } catch {
            failures += 1;
            if (failures >= maxFailures) onUnavailable(); else schedule();
        } finally {
            inFlight = false;
        }
    };

    poll();
    return () => {
        stopped = true;
        if (timer) clearTimeout(timer);
    };
}

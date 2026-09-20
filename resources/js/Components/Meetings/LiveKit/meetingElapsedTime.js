export function sharedMeetingElapsedSeconds(meeting, clock, now) {
    const startedAt = Date.parse(meeting.session_started_at);
    const serverNowAt = Date.parse(clock?.serverNowAt);

    if (meeting.status !== 'active' || !Number.isFinite(startedAt) || !Number.isFinite(serverNowAt)
        || !Number.isFinite(clock?.receivedAt) || !Number.isFinite(now) || startedAt > serverNowAt) return null;

    const elapsed = serverNowAt - startedAt + now - clock.receivedAt;

    return Number.isFinite(elapsed) ? Math.max(0, Math.floor(elapsed / 1000)) : null;
}

/**
 * Authoritative remaining time for a meeting recording.
 *
 * The countdown is a presentation of the stored scheduled_stop_at, never a timer
 * the browser owns. Two properties matter and both come from anchoring on server
 * time:
 *
 *  - every participant in the room computes the same remaining time regardless of
 *    how wrong their own clock is, because only the offset between server_now_at
 *    and the deadline is used;
 *  - a refresh or a reconnect resumes from the stored deadline instead of
 *    restarting, so 07:03 before a refresh is still about 07:01 after it.
 *
 * `clock` is the {serverNowAt, receivedAt} pair the application already anchors its
 * meeting clock on, where receivedAt is a monotonic reading taken when the response
 * arrived. `now` is the current monotonic reading.
 */

export function recordingRemainingSeconds(recording, clock, now) {
    if (!recording) return null;

    const deadline = Date.parse(recording.scheduled_stop_at);
    const serverNowAt = Date.parse(clock?.serverNowAt ?? recording.server_now_at);

    // A recording with no deadline is open-ended and has no countdown to show.
    if (!Number.isFinite(deadline) || !Number.isFinite(serverNowAt)) return null;
    if (!Number.isFinite(clock?.receivedAt) || !Number.isFinite(now)) return null;

    // The anchor is what the server said the time was when the response was built,
    // so the only thing added is how much local time has passed since then, and it
    // is subtracted: time passing locally must move the deadline closer, never
    // further away.
    //
    // A monotonic read earlier than the anchor cannot mean time has run backwards, so
    // it is treated as no elapsed time at all. Trusting it would push a deadline
    // further into the future than the server ever promised.
    const offset = Math.max(0, now - clock.receivedAt);
    const remaining = deadline - serverNowAt - offset;

    // Never negative: a deadline that has just passed shows 00:00 while the
    // authoritative stop is still on its way from the server.
    return Number.isFinite(remaining) ? Math.max(0, Math.ceil(remaining / 1000)) : null;
}

/**
 * Elapsed recording time, derived the same way, for the "recording 11:42" readout.
 */
export function recordingElapsedSeconds(recording, clock, now) {
    if (!recording) return null;

    const startedAt = Date.parse(recording.started_at);
    const serverNowAt = Date.parse(clock?.serverNowAt ?? recording.server_now_at);

    if (!Number.isFinite(startedAt) || !Number.isFinite(serverNowAt)) return null;
    if (!Number.isFinite(clock?.receivedAt) || !Number.isFinite(now) || startedAt > serverNowAt) return null;

    const elapsed = serverNowAt - startedAt + (now - clock.receivedAt);

    return Number.isFinite(elapsed) ? Math.max(0, Math.floor(elapsed / 1000)) : null;
}

export function formatRecordingClock(totalSeconds) {
    if (!Number.isFinite(totalSeconds) || totalSeconds < 0) return '00:00';

    const seconds = Math.floor(totalSeconds);
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainder = seconds % 60;

    return `${hours > 0 ? `${String(hours).padStart(2, '0')}:` : ''}${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`;
}

/**
 * The single value the recording badge shows.
 *
 * A recording with a deadline counts down to it, because that is what the teacher
 * chose and what the server will enforce. An open-ended one counts up, because
 * there is nothing to count down to.
 */
export function recordingBadgeSeconds(recording, clock, now) {
    if (!recording || !recordingLive(recording)) return null;

    const remaining = recordingRemainingSeconds(recording, clock, now);

    if (remaining !== null) return remaining;

    return recordingElapsedSeconds(recording, clock, now);
}

export function recordingLive(recording) {
    return recording?.status === 'starting' || recording?.status === 'recording';
}

export function recordingSettling(recording) {
    return recording?.status === 'stopping' || recording?.status === 'processing';
}

/**
 * The state the start control renders from.
 *
 * `canStart` never depends on the browser's own idea of the state: it is the
 * server's projection of whether this viewer may record, and the meeting being
 * live. A student therefore never sees the control at all.
 */
export function startRecordingControlState({meeting, recording, requesting, busy}) {
    const canRecord = Boolean(meeting?.can_start_recording);

    if (!canRecord) return {kind: 'hidden'};
    if (busy || requesting) return {kind: 'starting'};
    if (recordingLive(recording)) return {kind: 'stop', recording};
    if (recordingSettling(recording)) return {kind: 'stopping'};

    return {kind: 'start'};
}

/**
 * Validate a custom duration before it is sent.
 *
 * The bounds mirror the server's, but this only exists to give immediate feedback:
 * the server validates independently and computes the deadline from its own clock.
 */
export function validateCustomDuration(raw, {min, max}) {
    const value = String(raw ?? '').trim();

    if (value === '') return {valid: false, key: 'meetingRoom.recording.customDurationRequired'};
    if (!/^\d+$/.test(value)) return {valid: false, key: 'meetingRoom.recording.customDurationWhole'};

    const minutes = Number(value);
    if (minutes < min) return {valid: false, key: 'meetingRoom.recording.customDurationTooShort', values: {min}};
    if (minutes > max) return {valid: false, key: 'meetingRoom.recording.customDurationTooLong', values: {max}};

    return {valid: true, minutes};
}

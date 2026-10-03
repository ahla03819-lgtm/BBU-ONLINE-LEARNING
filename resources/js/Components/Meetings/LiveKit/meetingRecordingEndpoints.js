/**
 * Canonical recording endpoints for a meeting, and when leaving may be announced.
 *
 * The endpoints are a pure function of the meeting's own identifiers, so they can
 * always be derived. Deriving them here rather than only where the session is
 * originally built means a participant who arrives straight on the room URL, by deep
 * link or into a second tab, still has a recording API: without that they would never
 * poll the recording state, so they would not see the recording indicator, and their
 * explicit leave would never reach the server, so a recording they started would keep
 * running after they walked out.
 *
 * Values supplied by the session still win, which keeps the lobby's URLs authoritative
 * if they ever differ, and keeps this from becoming a second source of truth.
 *
 * The leave decision is here for the same reason. Only a person choosing to leave may
 * be announced, because that is the one thing the server cannot infer for itself: a
 * refresh, a reconnect, a provider disconnect and a full-to-mini switch all look like
 * a participant disappearing, and treating any of them as a departure would end a
 * recording the teacher never stopped.
 *
 * Kept free of React and the DOM so it can be exercised directly.
 */

export const recordingEndpointsFor = ({schoolClass, meeting, recordingUrl, leaveUrl} = {}) => {
    const classId = schoolClass?.id;
    const meetingUuid = meeting?.uuid;

    // Without both identifiers there is nothing safe to build a path from, and a
    // half-built URL would request the wrong meeting rather than no meeting.
    const derivable = Number.isFinite(Number(classId)) && Number(classId) > 0 && typeof meetingUuid === 'string' && meetingUuid !== '';

    const base = derivable ? `/collaboration/classes/${classId}/meetings/${meetingUuid}` : null;

    return {
        recordingUrl: recordingUrl || (base ? `${base}/recordings` : null),
        leaveUrl: leaveUrl || (base ? `${base}/leave` : null),
    };
};

/**
 * Whether a departure may be announced to the server as an explicit leave.
 *
 * Only a deliberate departure qualifies. Everything else that ends a client's
 * presence is silent, because the recording must survive all of it.
 */
export const shouldSignalExplicitLeave = (cause) => cause === 'explicit-leave';

export const EXPLICIT_LEAVE_CAUSE = 'explicit-leave';

/**
 * Causes that end a client's presence without anyone leaving the meeting. Listed so
 * the rule is readable at its call site and so each one can be pinned by a test.
 */
export const SILENT_DEPARTURE_CAUSES = Object.freeze([
    'refresh',
    'reconnect',
    'unmount',
    'provider-disconnect',
    'full-mini-toggle',
    'end-meeting',
]);

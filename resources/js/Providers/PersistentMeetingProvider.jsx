import React, {createContext, useCallback, useContext, useEffect, useRef, useState} from 'react';
import {router} from '@inertiajs/react';
import MeetingRoomExperience from '../Components/Meetings/LiveKit/MeetingRoomExperience';
import {clearMeetingMediaIntent} from '../Components/Meetings/LiveKit/meetingMediaIntent';
import {echo} from '../realtime/echo';
import {createMeetingLeaveTransaction} from './meetingLeaveTransaction';
import {meetingPath, shouldNavigateToLobby} from '../Components/Meetings/LiveKit/meetingEndTeardown';
import {useMeetingRecording} from '../Hooks/Meetings/useMeetingRecording';
import {EXPLICIT_LEAVE_CAUSE, recordingEndpointsFor, shouldSignalExplicitLeave} from '../Components/Meetings/LiveKit/meetingRecordingEndpoints';

const PersistentMeetingContext = createContext(null);

export function PersistentMeetingProvider({children}) {
    const [session, setSession] = useState(null);
    const sessionRef = useRef(null);
    const leaveTransactionRef = useRef(null);
    const [pathname, setPathname] = useState(() => window.location.pathname);

    if (!leaveTransactionRef.current) leaveTransactionRef.current = createMeetingLeaveTransaction();

    const clearSession = useCallback(async ({returnToLobby = false, disconnectRoom, destination, fallbackNavigation = false} = {}) => {
        const current = sessionRef.current;
        if (!current) return {status: 'cleared'};
        const lobbyUrl = destination ?? current.lobbyUrl;
        // Decided once, here, together with returnToLobby. cleanup() and
        // disconnect() are awaited before navigate() runs, and the URL can move
        // in that window; re-reading it there could cancel a terminal navigation
        // this client already committed to, releasing the session while it is
        // still on the room page and leaving that page blank.
        const currentPath = window.location.pathname;

        return leaveTransactionRef.current({
            returnToLobby,
            cleanup: async () => {
            try {
                await current.onLeave?.();
            } catch {
                // Clear the local session even if the best-effort admission request fails.
            }
            },
            disconnect: async () => {
            try {
                await disconnectRoom?.();
            } catch {
                // Continue clearing the application session if provider disconnect fails.
            }
            },
            navigate: () => {
                if (!shouldNavigateToLobby({returnToLobby, currentPath, roomUrl: current.roomUrl})) return Promise.resolve({status: 'success'});

                return new Promise((resolve) => {
                    let status = 'finished';
                    router.visit(lobbyUrl, {
                        replace: true,
                        onSuccess: () => { status = 'success'; },
                        onError: () => {
                            if (!fallbackNavigation) {
                                status = 'error';
                                return;
                            }

                            status = 'fallback';
                            window.location.assign(lobbyUrl);
                        },
                        onCancel: () => {
                            if (!fallbackNavigation) {
                                status = 'cancelled';
                                return;
                            }

                            status = 'fallback';
                            window.location.assign(lobbyUrl);
                        },
                        onFinish: () => resolve({status}),
                    });
                });
            },
            clearSession: () => {
                clearMeetingMediaIntent(current.mediaIntentKey);
                sessionRef.current = null;
                setSession(null);
            },
        });
    }, []);

    /**
     * One recording API per active meeting session.
     *
     * It is keyed on the session so a new meeting never inherits the previous
     * meeting's recording state, and it lives here rather than in the room page so
     * the recording survives a full-to-mini switch and a reconnect without ever
     * being re-created.
     *
     * The endpoints are derived from the meeting's own identifiers when the session
     * did not carry them, so a participant who lands directly on the room URL still
     * polls the recording state and still reports an explicit leave.
     */
    const endpoints = recordingEndpointsFor({
        schoolClass: session?.schoolClass,
        meeting: session?.meeting,
        recordingUrl: session?.recordingUrl,
        leaveUrl: session?.leaveUrl,
    });

    const recording = useMeetingRecording({
        meeting: session?.meeting,
        initialRecording: session?.meeting?.recording,
        recordingUrl: endpoints.recordingUrl,
        leaveUrl: endpoints.leaveUrl,
        minDurationMinutes: session?.meeting?.recording_min_duration_minutes,
        maxDurationMinutes: session?.meeting?.recording_max_duration_minutes,
    });

    const startMeeting = useCallback((nextSession) => {
        const current = sessionRef.current;

        if (current && current.meeting.uuid !== nextSession.meeting.uuid) {
            return {ok: false, message: 'Leave the current meeting before joining another meeting.'};
        }

        if (current) return {ok: true, existing: true};

        const roomUrlPath = meetingPath(nextSession.roomUrl);
        sessionRef.current = nextSession;
        setSession(nextSession);
        setPathname(roomUrlPath);
        if (window.location.pathname !== roomUrlPath) router.visit(nextSession.roomUrl);

        return {ok: true, existing: false};
    }, []);

    const returnToMeeting = useCallback(() => {
        const current = sessionRef.current;
        if (!current) return;

        router.visit(current.roomUrl);
    }, []);

    const leaveMeeting = useCallback((disconnectRoom) => {
        const current = sessionRef.current;

        // This is the application's only explicit leave intent, and it is what lets
        // a recording this person started be closed. It is announced before the
        // local teardown but is never awaited into it: leaving must succeed, and
        // must stay as reliable across a refresh or a reconnect as it always was,
        // whatever the server happens to say back.
        //
        // Only a deliberate departure is announced. A refresh, a reconnect, a
        // provider disconnect and a full-to-mini switch all end a client's presence
        // while the person is still in the meeting, and announcing any of them would
        // stop a recording the teacher never stopped.
        if (shouldSignalExplicitLeave(EXPLICIT_LEAVE_CAUSE)) {
            void recording.notifyExplicitLeave();
        }

        return clearSession({
            returnToLobby: Boolean(current) && window.location.pathname === meetingPath(current.roomUrl),
            disconnectRoom,
        });
    }, [clearSession, recording]);

    const endMeeting = useCallback((disconnectRoom) => {
        const current = sessionRef.current;
        if (!current) return Promise.resolve({status: 'cleared'});

        // Preserve the destination before teardown: Room will unmount once the
        // successful navigation clears this session.
        const destination = current.lobbyUrl;
        return clearSession({
            returnToLobby: window.location.pathname === meetingPath(current.roomUrl),
            disconnectRoom,
            destination,
            fallbackNavigation: true,
        });
    }, [clearSession]);

    useEffect(() => router.on('navigate', () => setPathname(window.location.pathname)), []);

    useEffect(() => {
        if (!session || !echo) return;

        const channel = echo.private(`meetings.class.${session.schoolClass.id}`);
        const updateMeeting = ({meeting: update}) => {
            const current = sessionRef.current;
            if (!current || current.meeting.uuid !== update.uuid || update.lifecycle_version <= current.meeting.lifecycle_version) return;

            const next = {...current, meeting: {...current.meeting, ...update}};
            sessionRef.current = next;
            setSession(next);
        };
        const events = ['scheduled', 'updated', 'started', 'ending', 'ended', 'cancelled'];
        events.forEach((event) => channel.listen(`.meeting.${event}`, updateMeeting));
        const participantRemoved = ({participant}) => {
            const current = sessionRef.current;
            if (participant.reference === current?.participantReference) {
                clearSession({returnToLobby: window.location.pathname === meetingPath(current.roomUrl)});
            }
        };
        channel.listen('.meeting.participant-removed', participantRemoved);
        const recordingChanged = ({meeting_uuid: meetingUuid, recording: projection}) => {
            if (!meetingUuid || meetingUuid !== sessionRef.current?.meeting?.uuid) return;
            // A broadcast carries the same authoritative projection the status
            // endpoint returns, including its own server clock, so the countdown a
            // participant sees is still anchored on server time.
            recording.applyBroadcast({recording: projection, server_now_at: projection?.server_now_at});
        };
        channel.listen('.meeting.recording-changed', recordingChanged);

        return () => {
            events.forEach((event) => channel.stopListening(`.meeting.${event}`, updateMeeting));
            channel.stopListening('.meeting.participant-removed', participantRemoved);
            channel.stopListening('.meeting.recording-changed', recordingChanged);
        };
    }, [clearSession, recording.applyBroadcast, session?.schoolClass.id]);

    useEffect(() => {
        if (session && ['ending', 'ended', 'cancelled'].includes(session.meeting.status)) {
            // An Echo terminal update may arrive before the initiating host's
            // End callback. It must use the same no-blank-screen route as End.
            endMeeting();
        }
    }, [endMeeting, session]);

    const mode = session && pathname === meetingPath(session.roomUrl) ? 'full' : 'mini';
    const value = {activeMeeting: session, startMeeting, leaveMeeting, returnToMeeting};

    return <PersistentMeetingContext.Provider value={value}>
        {children}
        {session && <MeetingRoomExperience credentials={session.credentials} meeting={session.meeting} clock={session.clock} schoolClass={session.schoolClass} recording={recording} initialMedia={session.initialMedia} mediaIntent={session.mediaIntent} mediaIntentKey={session.mediaIntentKey} mode={mode} onReturn={returnToMeeting} onLeave={leaveMeeting} onEnd={endMeeting}/>}
    </PersistentMeetingContext.Provider>;
}

export function usePersistentMeeting() {
    const context = useContext(PersistentMeetingContext);

    if (!context) throw new Error('usePersistentMeeting must be used within PersistentMeetingProvider.');

    return context;
}

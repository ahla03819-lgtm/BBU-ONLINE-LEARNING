import React, {createContext, useCallback, useContext, useEffect, useRef, useState} from 'react';
import {router} from '@inertiajs/react';
import MeetingRoomExperience from '../Components/Meetings/LiveKit/MeetingRoomExperience';
import {clearMeetingMediaIntent} from '../Components/Meetings/LiveKit/meetingMediaIntent';
import {echo} from '../realtime/echo';

const PersistentMeetingContext = createContext(null);

const meetingPath = (url) => new URL(url, window.location.origin).pathname;

export function PersistentMeetingProvider({children}) {
    const [session, setSession] = useState(null);
    const sessionRef = useRef(null);
    const clearInProgressRef = useRef(false);
    const [pathname, setPathname] = useState(() => window.location.pathname);

    const clearSession = useCallback(async ({returnToLobby = false, disconnectRoom} = {}) => {
        const current = sessionRef.current;
        if (!current || clearInProgressRef.current) return;

        clearInProgressRef.current = true;
        try {
            clearMeetingMediaIntent(current.mediaIntentKey);
            try {
                await current.onLeave?.();
            } catch {
                // Clear the local session even if the best-effort admission request fails.
            }
            try {
                await disconnectRoom?.();
            } catch {
                // Continue clearing the application session if provider disconnect fails.
            }

            sessionRef.current = null;
            setSession(null);
            if (returnToLobby && window.location.pathname === meetingPath(current.roomUrl)) {
                router.visit(current.lobbyUrl, {replace: true});
            }
        } finally {
            clearInProgressRef.current = false;
        }
    }, []);

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

    const leaveMeeting = useCallback((disconnectRoom) => clearSession({returnToLobby: true, disconnectRoom}), [clearSession]);

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

        return () => {
            events.forEach((event) => channel.stopListening(`.meeting.${event}`, updateMeeting));
            channel.stopListening('.meeting.participant-removed', participantRemoved);
        };
    }, [clearSession, session?.schoolClass.id]);

    useEffect(() => {
        if (session && ['ending', 'ended', 'cancelled'].includes(session.meeting.status)) {
            clearSession({returnToLobby: window.location.pathname === meetingPath(session.roomUrl)});
        }
    }, [clearSession, session]);

    const mode = session && pathname === meetingPath(session.roomUrl) ? 'full' : 'mini';
    const value = {activeMeeting: session, startMeeting, leaveMeeting, returnToMeeting};

    return <PersistentMeetingContext.Provider value={value}>
        {children}
        {session && <MeetingRoomExperience credentials={session.credentials} meeting={session.meeting} clock={session.clock} schoolClass={session.schoolClass} initialMedia={session.initialMedia} mediaIntent={session.mediaIntent} mediaIntentKey={session.mediaIntentKey} mode={mode} onReturn={returnToMeeting} onLeave={leaveMeeting}/>}
    </PersistentMeetingContext.Provider>;
}

export function usePersistentMeeting() {
    const context = useContext(PersistentMeetingContext);

    if (!context) throw new Error('usePersistentMeeting must be used within PersistentMeetingProvider.');

    return context;
}

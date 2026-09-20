import React, {createContext, useCallback, useContext, useEffect, useRef, useState} from 'react';
import {router} from '@inertiajs/react';
import MeetingRoomExperience from '../Components/Meetings/LiveKit/MeetingRoomExperience';
import {echo} from '../realtime/echo';

const PersistentMeetingContext = createContext(null);

const meetingPath = (url) => new URL(url, window.location.origin).pathname;

export function PersistentMeetingProvider({children}) {
    const [session, setSession] = useState(null);
    const sessionRef = useRef(null);
    const [pathname, setPathname] = useState(() => window.location.pathname);

    const clearSession = useCallback(({returnToLobby = false} = {}) => {
        const current = sessionRef.current;
        if (!current) return;

        sessionRef.current = null;
        setSession(null);
        current.onLeave?.();

        if (returnToLobby && window.location.pathname === meetingPath(current.roomUrl)) {
            window.history.replaceState(window.history.state, '', current.lobbyUrl);
            setPathname(meetingPath(current.lobbyUrl));
        }
    }, []);

    const startMeeting = useCallback((nextSession) => {
        const current = sessionRef.current;

        if (current && current.meeting.uuid !== nextSession.meeting.uuid) {
            return {ok: false, message: 'Leave the current meeting before joining another meeting.'};
        }

        if (current) return {ok: true, existing: true};

        sessionRef.current = nextSession;
        setSession(nextSession);
        setPathname(meetingPath(nextSession.roomUrl));
        router.visit(nextSession.roomUrl);

        return {ok: true, existing: false};
    }, []);

    const returnToMeeting = useCallback(() => {
        const current = sessionRef.current;
        if (!current) return;

        router.visit(current.roomUrl);
    }, []);

    const leaveMeeting = useCallback(() => clearSession({returnToLobby: true}), [clearSession]);

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
        {session && <MeetingRoomExperience credentials={session.credentials} meeting={session.meeting} schoolClass={session.schoolClass} initialMedia={session.initialMedia} mode={mode} onReturn={returnToMeeting} onLeave={leaveMeeting}/>}
    </PersistentMeetingContext.Provider>;
}

export function usePersistentMeeting() {
    const context = useContext(PersistentMeetingContext);

    if (!context) throw new Error('usePersistentMeeting must be used within PersistentMeetingProvider.');

    return context;
}

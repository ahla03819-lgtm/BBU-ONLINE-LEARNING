import React, {createContext, useCallback, useContext, useEffect, useMemo, useRef, useState} from 'react';
import {usePage} from '@inertiajs/react';
import {echo} from '../realtime/echo';
import ConversationCallExperience from '../Components/Conversations/ConversationCallExperience';
import {clearConversationCallMediaIntent, conversationCallMediaIntentKey, readConversationCallMediaIntent} from '../Components/Conversations/conversationCallMediaIntent';

const PersistentConversationCallContext = createContext(null);

const callKey = (call) => call?.uuid ?? call?.public_uuid ?? call?.id;

export function PersistentConversationCallProvider({children}) {
    const {auth} = usePage().props;
    const [incomingCall, setIncomingCall] = useState(null);
    const [outgoingCall, setOutgoingCall] = useState(null);
    const [activeCall, setActiveCall] = useState(null);
    const activeCallRef = useRef(null);
    const connectingCallRef = useRef(null);

    const normalizeCall = useCallback((call) => {
        if (!call) return null;

        return {
            uuid: call.uuid ?? call.public_uuid,
            type: call.type,
            status: call.status,
            started_at: call.started_at ?? null,
            conversation_uuid: call.conversation_uuid ?? call.conversation?.public_uuid ?? null,
            name: call.name ?? call.conversation?.name ?? 'Private call',
            initiator: call.initiator ?? {name: 'Caller', avatar_url: null},
            participants: call.participants ?? [],
        };
    }, []);

    const requestToken = useCallback(async (call) => {
        const response = await fetch(`/conversation-calls/${call.uuid}/token`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                Accept: 'application/json',
            },
        });

        if (!response.ok) {
            throw new Error('Unable to join the call right now.');
        }

        return response.json();
    }, []);

    const connectCall = useCallback(async (call, {mode = 'full', audioOnly = false, recovering = false} = {}) => {
        const normalized = normalizeCall(call);
        if (!normalized?.uuid) return null;

        const current = activeCallRef.current;
        if (current && callKey(current.call) === callKey(normalized)) {
            return current;
        }
        const pending = connectingCallRef.current;
        if (pending && pending.uuid === callKey(normalized)) return pending.promise;

        const promise = (async () => {
            const credentials = await requestToken(normalized);
            if (!credentials?.token || !credentials?.server_url) {
                throw new Error('Unable to establish the call connection right now.');
            }
            const connectedCall = {...normalized, started_at: credentials.started_at ?? normalized.started_at};
            const mediaIntentKey = conversationCallMediaIntentKey(connectedCall.uuid, auth?.user?.id);
            const mediaIntent = readConversationCallMediaIntent(mediaIntentKey);
            const serverClock = {serverNowAt: credentials.server_now_at, receivedAt: performance.now()};
            const nextSession = {call: connectedCall, credentials, mode, audioOnly, recovering, mediaIntentKey, mediaIntent, serverClock};
            activeCallRef.current = nextSession;
            setActiveCall(nextSession);
            setOutgoingCall(null);
            setIncomingCall(null);
            return nextSession;
        })();
        connectingCallRef.current = {uuid: callKey(normalized), promise};

        try {
            return await promise;
        } finally {
            if (connectingCallRef.current?.promise === promise) connectingCallRef.current = null;
        }
    }, [auth?.user?.id, normalizeCall, requestToken]);

    const setMode = useCallback((mode) => {
        setActiveCall((current) => {
            if (!current) return null;
            const next = {...current, mode};
            activeCallRef.current = next;
            return next;
        });
    }, []);

    const acceptIncomingCall = useCallback(async (call, {audioOnly = false} = {}) => {
        const normalized = normalizeCall(call);
        if (!normalized) return null;

        const response = await fetch(`/conversation-calls/${normalized.uuid}/respond`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({decision: 'accepted'}),
        });

        if (!response.ok) {
            throw new Error('Unable to accept the call.');
        }

        setIncomingCall(null);
        return connectCall(normalized, {mode: 'full', audioOnly});
    }, [connectCall, normalizeCall]);

    const declineIncomingCall = useCallback(async (call) => {
        const normalized = normalizeCall(call);
        if (!normalized) return false;

        const response = await fetch(`/conversation-calls/${normalized.uuid}/respond`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({decision: 'declined'}),
        });

        if (!response.ok) {
            throw new Error('Unable to decline the call.');
        }

        setIncomingCall(null);
        return true;
    }, [normalizeCall]);

    const cancelOutgoingCall = useCallback(async (call) => {
        const normalized = normalizeCall(call);
        if (!normalized) return false;

        try {
            await fetch(`/conversation-calls/${normalized.uuid}/cancel`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    Accept: 'application/json',
                },
            });
        } catch {
            // Best effort; the call status is server-authoritative.
        }

        setOutgoingCall(null);
        return true;
    }, [normalizeCall]);

    const leaveCall = useCallback(async (disconnectRoom) => {
        const current = activeCallRef.current;
        if (!current) return;

        try {
            await fetch(`/conversation-calls/${current.call.uuid}/leave`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    Accept: 'application/json',
                },
            });
        } catch {
            // Best effort: leave the call UI even if the server request fails.
        }

        try {
            await disconnectRoom?.();
        } catch {
            // Provider cleanup still proceeds if transport shutdown fails.
        }

        activeCallRef.current = null;
        clearConversationCallMediaIntent(current.mediaIntentKey);
        setActiveCall(null);
    }, []);

    useEffect(() => {
        if (!auth?.user?.id) return undefined;
        let cancelled = false;

        fetch('/conversation-calls/active', {headers: {Accept: 'application/json'}})
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('Unable to restore the active call.')))
            .then(({call}) => {
                if (!cancelled && call && !activeCallRef.current) {
                    return connectCall(call, {mode: 'full', recovering: true});
                }
                return null;
            })
            .catch(() => undefined);

        return () => { cancelled = true; };
    }, [auth?.user?.id, connectCall]);

    useEffect(() => {
        if (!echo || !auth?.user?.id) return undefined;

        const channel = echo.private(`incoming-call.${auth.user.id}`);
        const handleCallSignal = (event) => {
            const next = normalizeCall(event.call);
            if (!next) return;

            if (next.status === 'ringing') {
                setIncomingCall(next);
                return;
            }

            if (next.status === 'declined' || next.status === 'cancelled' || next.status === 'ended') {
                setIncomingCall((current) => (current && callKey(current) === callKey(next) ? null : current));
                setOutgoingCall((current) => (current && callKey(current) === callKey(next) ? null : current));
                if (activeCallRef.current && callKey(activeCallRef.current.call) === callKey(next)) {
                    clearConversationCallMediaIntent(activeCallRef.current.mediaIntentKey);
                    activeCallRef.current = null;
                    setActiveCall(null);
                }
            }
        };

        channel.listen('.conversation.call.started', handleCallSignal)
            .listen('.conversation.call.accepted', handleCallSignal)
            .listen('.conversation.call.declined', handleCallSignal)
            .listen('.conversation.call.cancelled', handleCallSignal)
            .listen('.conversation.call.ended', handleCallSignal)
            .listen('.conversation.call.left', handleCallSignal);

        return () => {
            channel.stopListening('.conversation.call.started', handleCallSignal);
            channel.stopListening('.conversation.call.accepted', handleCallSignal);
            channel.stopListening('.conversation.call.declined', handleCallSignal);
            channel.stopListening('.conversation.call.cancelled', handleCallSignal);
            channel.stopListening('.conversation.call.ended', handleCallSignal);
            channel.stopListening('.conversation.call.left', handleCallSignal);
        };
    }, [auth?.user?.id, normalizeCall]);

    useEffect(() => {
        if (!echo || !activeCall?.call?.uuid) return undefined;
        const channel = echo.private(`conversation-call.${activeCall.call.uuid}`);
        const closeOnTerminal = (event) => {
            const signal = event?.signal ?? event?.call?.status;
            if (!['declined', 'cancelled', 'ended'].includes(signal)) return;
            if (activeCallRef.current && callKey(activeCallRef.current.call) === activeCall.call.uuid) {
                clearConversationCallMediaIntent(activeCallRef.current.mediaIntentKey);
                activeCallRef.current = null;
                setActiveCall(null);
            }
        };
        channel.listen('.conversation.call.declined', closeOnTerminal)
            .listen('.conversation.call.cancelled', closeOnTerminal)
            .listen('.conversation.call.ended', closeOnTerminal)
            .listen('.conversation.call.left', closeOnTerminal);
        return () => {
            channel.stopListening('.conversation.call.declined', closeOnTerminal);
            channel.stopListening('.conversation.call.cancelled', closeOnTerminal);
            channel.stopListening('.conversation.call.ended', closeOnTerminal);
            channel.stopListening('.conversation.call.left', closeOnTerminal);
        };
    }, [activeCall?.call?.uuid]);

    useEffect(() => {
        if (!echo || !outgoingCall?.uuid) return undefined;
        const channelName = `conversation-call.${outgoingCall.uuid}`;
        const channel = echo.private(channelName);
        const handleAccepted = () => {
            setOutgoingCall(null);
            connectCall(outgoingCall, {mode: 'full'}).catch(() => setOutgoingCall(null));
        };
        const handleTerminal = () => setOutgoingCall(null);
        channel.listen('.conversation.call.accepted', handleAccepted)
            .listen('.conversation.call.declined', handleTerminal)
            .listen('.conversation.call.cancelled', handleTerminal)
            .listen('.conversation.call.ended', handleTerminal);
        return () => {
            channel.stopListening('.conversation.call.accepted', handleAccepted);
            channel.stopListening('.conversation.call.declined', handleTerminal);
            channel.stopListening('.conversation.call.cancelled', handleTerminal);
            channel.stopListening('.conversation.call.ended', handleTerminal);
        };
    }, [connectCall, outgoingCall]);

    const value = useMemo(() => ({
        incomingCall,
        outgoingCall,
        activeCall,
        setIncomingCall,
        setOutgoingCall,
        connectCall,
        acceptIncomingCall,
        declineIncomingCall,
        cancelOutgoingCall,
        leaveCall,
        setMode,
    }), [acceptIncomingCall, activeCall, cancelOutgoingCall, connectCall, declineIncomingCall, incomingCall, leaveCall, outgoingCall, setMode]);

    return <PersistentConversationCallContext.Provider value={value}>
        {children}
        {activeCall && <ConversationCallExperience call={activeCall.call} credentials={activeCall.credentials} mode={activeCall.mode} audioOnly={activeCall.audioOnly} recovering={activeCall.recovering} mediaIntent={activeCall.mediaIntent} mediaIntentKey={activeCall.mediaIntentKey} serverClock={activeCall.serverClock} userId={auth?.user?.id} onLeave={leaveCall} onModeChange={setMode}/>}
    </PersistentConversationCallContext.Provider>;
}

export function usePersistentConversationCall() {
    const context = useContext(PersistentConversationCallContext);

    if (!context) {
        throw new Error('usePersistentConversationCall must be used within PersistentConversationCallProvider.');
    }

    return context;
}

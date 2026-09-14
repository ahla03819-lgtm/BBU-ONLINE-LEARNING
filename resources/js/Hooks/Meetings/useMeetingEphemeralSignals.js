import {useCallback, useEffect, useRef, useState} from 'react';
import {useConnectionState, useLocalParticipant, useRoomContext} from '@livekit/components-react';
import {RoomEvent} from 'livekit-client';

const CHAT_TOPIC = 'meeting-chat';
const HAND_TOPIC = 'meeting-hand';
const REACTION_TOPIC = 'meeting-reaction';
const MAX_MESSAGE_LENGTH = 2000;
const reactions = new Set(['👍', '❤️', '👏', '😂', '😮']);

const decode = (payload) => {
    try { return JSON.parse(new TextDecoder().decode(payload)); } catch { return null; }
};
const identifier = () => globalThis.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(16).slice(2)}`;
const participantSummary = (participant) => ({identity: participant.identity, name: participant.name || 'Participant', metadata: participant.metadata || ''});

export default function useMeetingEphemeralSignals() {
    const room = useRoomContext();
    const connection = useConnectionState();
    const {localParticipant} = useLocalParticipant();
    const [messages, setMessages] = useState([]);
    const [raisedHands, setRaisedHands] = useState({});
    const [reactionEvents, setReactionEvents] = useState([]);
    const reactionTimers = useRef(new Map());

    const publish = useCallback(async (topic, body) => {
        if (connection !== 'connected') throw new Error('The meeting is not connected.');
        await localParticipant.publishData(new TextEncoder().encode(JSON.stringify(body)), {reliable: true, topic});
    }, [connection, localParticipant]);

    useEffect(() => {
        const received = (payload, participant, _kind, topic) => {
            if (!participant || ![CHAT_TOPIC, HAND_TOPIC, REACTION_TOPIC].includes(topic)) return;
            const packet = decode(payload);
            if (!packet || typeof packet !== 'object') return;
            const sender = participantSummary(participant);

            if (topic === CHAT_TOPIC) {
                const body = typeof packet.body === 'string' ? packet.body.trim() : '';
                if (!body || body.length > MAX_MESSAGE_LENGTH || typeof packet.id !== 'string') return;
                setMessages((items) => items.some((item) => item.id === packet.id) ? items : [...items, {id: packet.id, body, sentAt: typeof packet.sentAt === 'string' ? packet.sentAt : new Date().toISOString(), sender, local: false}]);
            }

            if (topic === HAND_TOPIC && typeof packet.raised === 'boolean') {
                setRaisedHands((hands) => ({...hands, [sender.identity]: packet.raised}));
            }

            if (topic === REACTION_TOPIC && reactions.has(packet.reaction) && typeof packet.id === 'string') {
                const event = {id: packet.id, reaction: packet.reaction, sender};
                setReactionEvents((items) => [...items, event].slice(-6));
                const timer = window.setTimeout(() => setReactionEvents((items) => items.filter((item) => item.id !== event.id)), 3500);
                reactionTimers.current.set(event.id, timer);
            }
        };
        const departed = (participant) => setRaisedHands((hands) => {
            const next = {...hands};
            delete next[participant.identity];
            return next;
        });
        room.on(RoomEvent.DataReceived, received);
        room.on(RoomEvent.ParticipantDisconnected, departed);
        return () => {
            room.off(RoomEvent.DataReceived, received);
            room.off(RoomEvent.ParticipantDisconnected, departed);
            reactionTimers.current.forEach((timer) => window.clearTimeout(timer));
            reactionTimers.current.clear();
        };
    }, [room]);

    const sendMessage = useCallback(async (value) => {
        const body = value.trim();
        if (!body) throw new Error('Write a message before sending.');
        if (body.length > MAX_MESSAGE_LENGTH) throw new Error(`Meeting chat messages can be up to ${MAX_MESSAGE_LENGTH} characters.`);
        const packet = {type: CHAT_TOPIC, id: identifier(), body, sentAt: new Date().toISOString()};
        await publish(CHAT_TOPIC, packet);
        setMessages((items) => [...items, {...packet, sender: participantSummary(localParticipant), local: true}]);
    }, [localParticipant, publish]);

    const localHandRaised = Boolean(raisedHands[localParticipant.identity]);
    const toggleHand = useCallback(async () => {
        const raised = !localHandRaised;
        await publish(HAND_TOPIC, {type: HAND_TOPIC, raised});
        setRaisedHands((hands) => ({...hands, [localParticipant.identity]: raised}));
    }, [localHandRaised, localParticipant.identity, publish]);

    const sendReaction = useCallback(async (reaction) => {
        if (!reactions.has(reaction)) return;
        const packet = {type: REACTION_TOPIC, id: identifier(), reaction};
        await publish(REACTION_TOPIC, packet);
        const event = {...packet, sender: participantSummary(localParticipant)};
        setReactionEvents((items) => [...items, event].slice(-6));
        const timer = window.setTimeout(() => setReactionEvents((items) => items.filter((item) => item.id !== event.id)), 3500);
        reactionTimers.current.set(event.id, timer);
    }, [localParticipant, publish]);

    return {messages, raisedHands, reactionEvents, localHandRaised, sendMessage, toggleHand, sendReaction, connected: connection === 'connected', maxMessageLength: MAX_MESSAGE_LENGTH};
}

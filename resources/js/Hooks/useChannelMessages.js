import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {echo} from '../realtime/echo';
import uploadRequest from '../Support/uploadRequest';
import useMessageReactions from './useMessageReactions';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;
const request = async (url, options = {}) => {
    const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), ...options.headers}, ...options});
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Request failed.');
    return data;
};
const mergeMessages = (current, incoming) => {
    const map = new Map(current.map(message => [String(message.id), message]));
    incoming.forEach(message => {
        const pending = [...map.values()].find(item => item.client_uuid && item.client_uuid === message.client_uuid && String(item.id).startsWith('pending:'));
        if (pending) map.delete(String(pending.id));
        map.set(String(message.id), message);
    });
    return [...map.values()].sort((a, b) => Number(a.id) - Number(b.id));
};

export default function useChannelMessages({schoolClassId, channelId, user, onMessageReceived}) {
    const base = `/collaboration/classes/${schoolClassId}/channels/${channelId}`;
    const [messages, setMessages] = useState([]);
    const [hasMore, setHasMore] = useState(false);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState(null);
    const [connection, setConnection] = useState(echo ? 'connecting' : 'offline');
    const [members, setMembers] = useState([]);
    const [typing, setTyping] = useState({});
    const channelRef = useRef(null);
    const messagesRef = useRef([]);
    const lastTypingSent = useRef(0);
    const typingTimers = useRef({});
    const onMessageReceivedRef = useRef(onMessageReceived);
    const heardMessages = useRef(new Set());
    const reactions = useMessageReactions({base, setMessages});

    const load = useCallback(async (query = '') => {
        const data = await request(`${base}/messages${query}`);
        setMessages(current => mergeMessages(current, data.messages));
        setHasMore(data.has_more);
        return data;
    }, [base]);

    useEffect(() => {
        setMessages([]); setLoading(true); setLoadError(null); setMembers([]); setTyping({});
        load().catch(error => setLoadError(error.message)).finally(() => setLoading(false));
    }, [load]);

    useEffect(() => { messagesRef.current = messages; }, [messages]);
    useEffect(() => { onMessageReceivedRef.current = onMessageReceived; }, [onMessageReceived]);

    const recover = useCallback(() => {
        const highest = messagesRef.current.reduce((max, message) => Number.isInteger(Number(message.id)) ? Math.max(max, Number(message.id)) : max, 0);
        return load(`?after_id=${highest}`);
    }, [load]);

    useEffect(() => {
        if (!echo) return undefined;
        const name = `collaboration.channel.${channelId}`;
        const channel = echo.join(name)
            .here(users => setMembers(users))
            .joining(member => setMembers(current => current.some(item => String(item.id) === String(member.id)) ? current : [...current, member]))
            .leaving(member => setMembers(current => current.filter(item => String(item.id) !== String(member.id))))
            .listen('.message.sent', event => {
                const messageId = String(event.message?.id ?? '');
                if (messageId && String(event.message?.sender?.id) !== String(user.id) && !heardMessages.current.has(messageId)) {
                    heardMessages.current.add(messageId);
                    if (heardMessages.current.size > 100) heardMessages.current.delete(heardMessages.current.values().next().value);
                    onMessageReceivedRef.current?.(event.message);
                }
                setMessages(current => mergeMessages(current, [event.message]));
            })
            .listen('.message.updated', event => setMessages(current => mergeMessages(current, [event.message])))
            .listen('.message.hidden', event => setMessages(current => mergeMessages(current, [event.message])))
            .listen('.message.reactions.changed', event => reactions.applyReactionEvent(event.reactions))
            .listenForWhisper('typing', member => {
                if (!member || String(member.id) === String(user.id)) return;
                setTyping(current => ({...current, [member.id]: member.name}));
                clearTimeout(typingTimers.current[member.id]);
                typingTimers.current[member.id] = setTimeout(() => setTyping(current => { const next = {...current}; delete next[member.id]; return next; }), 4000);
            });
        channelRef.current = channel;
        const socket = echo.connector.pusher.connection;
        const connected = () => { setConnection('connected'); recover().catch(() => setConnection('reconnecting')); };
        const connecting = () => setConnection('reconnecting');
        socket.bind('connected', connected); socket.bind('connecting', connecting); socket.bind('unavailable', connecting); socket.bind('disconnected', connecting);
        if (socket.state === 'connected') setConnection('connected');
        return () => {
            Object.values(typingTimers.current).forEach(clearTimeout);
            socket.unbind('connected', connected); socket.unbind('connecting', connecting); socket.unbind('unavailable', connecting); socket.unbind('disconnected', connecting);
            echo.leave(name); channelRef.current = null; setTyping({}); setMembers([]);
        };
    }, [channelId, reactions.applyReactionEvent, recover, user.id]);

    const send = useCallback(async ({body, replyTo = null, attachments = [], clientUuid = crypto.randomUUID()}) => {
        const optimistic = {id: `pending:${clientUuid}`, channel_id: channelId, client_uuid: clientUuid, type: 'text', body: body || null, attachments: attachments.map(item => ({...item, display_name: item.file.name, size_bytes: item.file.size})), _attachmentFiles: attachments, reactions: {version: 0, counts: {}, current_user: null}, sender: {id: user.id, name: user.name}, reply_to: replyTo, edited_at: null, hidden_at: null, created_at: new Date().toISOString(), pending: true, uploadProgress: attachments.length ? 0 : undefined};
        setMessages(current => mergeMessages(current, [optimistic]));
        try {
            const data = attachments.length ? await uploadRequest(`${base}/messages`, {client_uuid: clientUuid, body, reply_to_id: replyTo?.id, attachments}, progress => setMessages(current => current.map(message => message.client_uuid === clientUuid ? {...message, uploadProgress: progress} : message))) : await request(`${base}/messages`, {method: 'POST', body: JSON.stringify({client_uuid: clientUuid, body, reply_to_id: replyTo?.id})});
            attachments.forEach(item => item.previewUrl && URL.revokeObjectURL(item.previewUrl));
            setMessages(current => mergeMessages(current, [data.message]));
        } catch (error) {
            setMessages(current => current.map(message => message.client_uuid === clientUuid ? {...message, pending: false, failed: true, error: error.message} : message));
        }
        return clientUuid;
    }, [base, channelId, user]);

    const mutate = useCallback(async (message, path, payload = {}, method = 'PATCH') => {
        const data = await request(`${base}/messages/${message.id}${path}`, {method, body: JSON.stringify(payload)});
        setMessages(current => mergeMessages(current, [data.message]));
    }, [base]);

    const markRead = useCallback(async messageId => request(`${base}/read-state`, {method: 'PUT', body: JSON.stringify({message_id: messageId})}), [base]);
    useEffect(() => {
        const latest = [...messages].reverse().find(message => !String(message.id).startsWith('pending:'));
        if (latest) markRead(latest.id).catch(() => {});
    }, [markRead, messages]);

    const whisperTyping = useCallback(active => {
        const now = Date.now();
        if (!active || now - lastTypingSent.current >= 1000) {
            channelRef.current?.whisper('typing', {id: user.id, name: user.name, active});
            lastTypingSent.current = now;
        }
    }, [user]);

    const loadOlder = useCallback(() => {
        const first = messages.find(message => !String(message.id).startsWith('pending:'));
        return first ? load(`?before_id=${first.id}`) : Promise.resolve();
    }, [load, messages]);

    return useMemo(() => ({messages, hasMore, loading, loadError, connection, members, typing: Object.values(typing), send, retry: message => send({body: message.body, replyTo: message.reply_to, attachments: message._attachmentFiles || [], clientUuid: message.client_uuid}), update: (message, body) => mutate(message, '', {body}), hide: message => mutate(message, '/hide'), moderate: (message, reason) => mutate(message, '/moderate', {reason}), setReaction: reactions.setReaction, loadOlder, whisperTyping}), [messages, hasMore, loading, loadError, connection, members, typing, send, mutate, reactions.setReaction, loadOlder, whisperTyping]);
}

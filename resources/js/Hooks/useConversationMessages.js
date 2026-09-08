import {useCallback, useEffect, useRef, useState} from 'react';
import {echo} from '../realtime/echo';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

export default function useConversationMessages({conversation, currentUser, initialMessages = []}) {
    const [messages, setMessages] = useState(initialMessages);
    const [typingUsers, setTypingUsers] = useState([]);
    const [pinnedMessageId, setPinnedMessageId] = useState(null);
    const received = useRef(new Set());
    const typingTimers = useRef(new Map());

    useEffect(() => { setMessages(initialMessages); setTypingUsers([]); setPinnedMessageId(conversation?.pinned_message_id || null); received.current.clear(); }, [conversation?.uuid]);
    const merge = useCallback(message => setMessages(current => current.some(item => String(item.id) === String(message.id)) ? current : [...current, message]), []);
    const update = useCallback((id, callback) => setMessages(current => current.map(item => String(item.id) === String(id) ? callback(item) : item)), []);
    const request = useCallback(async (url, options = {}) => {
        const response = await fetch(url, {credentials: 'same-origin', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(options.body ? {'Content-Type': 'application/json'} : {}), ...options.headers}, ...options});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'The request could not be completed.');
        return data;
    }, []);
    useEffect(() => {
        if (!echo || !conversation?.uuid) return undefined;
        const name = `conversation.${conversation.uuid}`;
        const channel = echo.private(name)
            .listen('.conversation.message.sent', event => { const message = event.message; if (!message || received.current.has(String(message.id))) return; received.current.add(String(message.id)); merge(message); })
            .listen('.conversation.message.edited', event => update(event.message_id, message => ({...message, body: event.body, edited: true, updated_at: event.edited_at})))
            .listen('.conversation.message.deleted', event => update(event.message_id, message => ({...message, body: null, attachments: [], deleted: true, reactions: {}})))
            .listen('.conversation.message.reactions', event => update(event.message_id, message => ({...message, reactions: event.reactions || {}})))
            .listen('.conversation.messages.read', event => { if (String(event.reader_id) === String(currentUser?.id)) return; setMessages(current => current.map(message => event.message_ids?.includes(message.id) ? {...message, read: true} : message)); })
            .listen('.conversation.pin.changed', event => setPinnedMessageId(event.pinned_message_id || null))
            .listen('.conversation.typing', event => {
                const user = event.user; if (!user || String(user.id) === String(currentUser?.id)) return;
                setTypingUsers(current => [...current.filter(item => String(item.id) !== String(user.id)), ...(event.typing ? [user] : [])]);
                window.clearTimeout(typingTimers.current.get(user.id));
                if (event.typing) typingTimers.current.set(user.id, window.setTimeout(() => setTypingUsers(current => current.filter(item => String(item.id) !== String(user.id))), 5000));
            });
        return () => { typingTimers.current.forEach(timer => window.clearTimeout(timer)); typingTimers.current.clear(); echo.leave(name); };
    }, [conversation?.uuid, currentUser?.id, merge, update]);
    const send = useCallback(async (body, attachments = [], replyToMessageId = null) => {
        const form = new FormData(); form.append('body', body || ''); if (replyToMessageId) form.append('reply_to_message_id', replyToMessageId);
        attachments.forEach(file => form.append('attachments[]', file));
        const response = await fetch(`/conversations/${conversation.uuid}/messages`, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json', 'X-CSRF-TOKEN': csrf()}, body: form});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Could not send this message.');
        received.current.add(String(data.message.id)); merge(data.message); return data.message;
    }, [conversation?.uuid, merge]);
    return {messages, setMessages, send, request, update, typingUsers, pinnedMessageId, setPinnedMessageId};
}

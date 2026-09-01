import {useCallback, useEffect, useRef, useState} from 'react';
import {echo} from '../realtime/echo';
import {useAppSounds} from '../Sound/AppSounds';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

export default function useConversationMessages({conversation, currentUser, initialMessages = []}) {
    const [messages, setMessages] = useState(initialMessages);
    const {play} = useAppSounds();
    const received = useRef(new Set());

    useEffect(() => { setMessages(initialMessages); received.current.clear(); }, [conversation?.uuid]);
    const merge = useCallback((message) => setMessages(current => current.some(item => String(item.id) === String(message.id)) ? current : [...current, message]), []);
    useEffect(() => {
        if (!echo || !conversation?.uuid) return undefined;
        const name = `conversation.${conversation.uuid}`;
        const channel = echo.private(name).listen('.conversation.message.sent', event => {
            const message = event.message; if (!message || received.current.has(String(message.id))) return;
            received.current.add(String(message.id)); merge(message);
            if (String(message.sender?.id) !== String(currentUser.id)) play('notification', `conversation:${message.id}`);
        });
        return () => echo.leave(name);
    }, [conversation?.uuid, currentUser.id, merge, play]);

    const send = useCallback(async (body) => {
        const response = await fetch(`/conversations/${conversation.uuid}/messages`, {method: 'POST', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf()}, body: JSON.stringify({body})});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Could not send this message.');
        received.current.add(String(data.message.id)); merge(data.message); return data.message;
    }, [conversation?.uuid, merge]);
    return {messages, send};
}

import {useCallback} from 'react';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content;

export default function useMessageReactions({base, setMessages}) {
    const apply = useCallback(reactions => setMessages(current => current.map(message => String(message.id) === String(reactions.message_id) && Number(reactions.version) >= Number(message.reactions?.version || 0) ? {...message, reactions: {...reactions, current_user: reactions.current_user ?? message.reactions?.current_user ?? null}} : message)), [setMessages]);
    const mutate = useCallback(async (message, reaction) => {
        if (message._reactionPending) return;
        const before = message.reactions || {version: 0, counts: {}, current_user: null};
        const counts = {...before.counts};
        if (before.current_user) counts[before.current_user] = Math.max(0, Number(counts[before.current_user] || 0) - 1);
        if (reaction) counts[reaction] = Number(counts[reaction] || 0) + 1;
        setMessages(current => current.map(item => String(item.id) === String(message.id) ? {...item, _reactionPending: true, reactions: {...before, counts, current_user: reaction}} : item));
        const response = await fetch(`${base}/messages/${message.id}/reaction`, {method: reaction ? 'PUT' : 'DELETE', credentials: 'same-origin', headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf()}, body: reaction ? JSON.stringify({reaction}) : undefined});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            setMessages(current => current.map(item => String(item.id) === String(message.id) ? {...item, _reactionPending: false, reactions: before} : item));
            throw new Error(data.message || Object.values(data.errors || {})[0]?.[0] || 'Reaction failed.');
        }
        apply(data.reactions);
        setMessages(current => current.map(item => String(item.id) === String(message.id) ? {...item, _reactionPending: false} : item));
    }, [apply, base, setMessages]);

    return {setReaction: mutate, applyReactionEvent: apply};
}

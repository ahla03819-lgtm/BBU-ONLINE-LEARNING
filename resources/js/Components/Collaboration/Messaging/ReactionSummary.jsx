import React, {useState} from 'react';

const catalog = {like: '👍', love: '❤️', laugh: '😂', surprised: '😮', sad: '😢', celebrate: '🎉'};
export default function ReactionSummary({message, canReact, onReact}) {
    const [pending, setPending] = useState(false);
    if (message.hidden_at || message.type === 'system') return null;
    const choose = async reaction => { setPending(true); try { await onReact(message, message.reactions?.current_user === reaction ? null : reaction); } finally { setPending(false); } };
    return <div className="mt-2 flex flex-wrap gap-1">{Object.entries(catalog).map(([code, emoji]) => {
        const count = message.reactions?.counts?.[code] || 0; const selected = message.reactions?.current_user === code;
        if (!canReact && !count) return null;
        return <button type="button" disabled={pending || message._reactionPending || !canReact} key={code} onClick={() => choose(code)} className={`rounded-full border px-2 py-1 text-xs ${selected ? 'border-indigo-500 bg-indigo-100' : 'bg-white'}`}>{emoji}{count ? ` ${count}` : ''}</button>;
    })}</div>;
}

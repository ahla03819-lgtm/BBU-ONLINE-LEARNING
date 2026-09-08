import React from 'react';

export default function TypingIndicator({users = []}) {
    if (!users.length) return null;
    const names = users.map(user => user.name);
    const text = names.length === 1 ? `${names[0]} is typing…` : names.length === 2 ? `${names[0]} and ${names[1]} are typing…` : `${names[0]} and ${names.length - 1} others are typing…`;
    return <p role="status" className="px-5 py-1 text-xs font-medium italic text-slate-500">{text}</p>;
}

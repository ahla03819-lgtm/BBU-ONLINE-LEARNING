import React, {useEffect, useRef} from 'react';
import MessageItem from './MessageItem';

export default function MessageList({messages, hasMore, loading, loadOlder, ...actions}) {
    const end = useRef(null);
    useEffect(() => { end.current?.scrollIntoView({block: 'nearest'}); }, [messages.length]);
    return <div className="space-y-3">
        {hasMore && <button className="w-full rounded-lg border p-2 text-sm" onClick={loadOlder}>Load older messages</button>}
        {loading && <p className="text-center text-sm text-slate-500">Loading messages…</p>}
        {!loading && !messages.length && <p className="text-center text-sm text-slate-500">No messages yet.</p>}
        {messages.map(message => <MessageItem key={message.id} message={message} {...actions}/>) }
        <div ref={end}/>
    </div>;
}

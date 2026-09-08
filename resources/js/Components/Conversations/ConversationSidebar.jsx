import React from 'react';
import {Link} from '@inertiajs/react';
import Icon from '../UI/Icon';
import UserAvatar from '../UI/UserAvatar';

export function ConversationAvatar({conversation, size = 'md'}) {
    if (conversation.type === 'direct') return <UserAvatar name={conversation.name} avatarUrl={conversation.avatar_url} size={size}/>;
    return <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-100 text-sm font-bold text-blue-800">{conversation.name?.split(/\s+/).map(word => word[0]).join('').slice(0, 2).toUpperCase()}</span>;
}

const preview = conversation => {
    const message = conversation.latest_message;
    if (!message) return 'No messages yet';
    if (message.deleted) return 'Message deleted';
    if (message.body) return message.body;
    const type = message.attachments?.[0]?.type;
    return type === 'voice' ? 'Voice message' : type === 'image' ? 'Photo' : type === 'video' ? 'Video' : type ? 'Document' : 'No messages yet';
};

export default function ConversationSidebar({conversations, selectedUuid, onCreateGroup}) {
    const groups = [
        ['Direct chats', conversations.filter(item => item.type === 'direct')],
        ['Groups', conversations.filter(item => item.type === 'group')],
    ];

    return <aside className="flex min-h-[38rem] flex-col rounded-3xl border border-slate-200 bg-white p-4 shadow-sm lg:min-h-0">
        <div className="flex items-center justify-between gap-3 px-1">
            <div><p className="text-xs font-bold uppercase tracking-wide text-blue-700">Private collaboration</p><h1 className="mt-1 text-xl font-bold text-slate-900">Chats</h1></div>
            <button type="button" onClick={onCreateGroup} className="inline-flex h-10 items-center gap-2 rounded-xl bg-blue-800 px-3 text-sm font-bold text-white transition hover:bg-blue-900"><Icon name="plus" className="h-4 w-4"/>Group</button>
        </div>
        <div className="mt-5 min-h-0 flex-1 space-y-5 overflow-y-auto pr-1">
            {groups.map(([title, items]) => <section key={title}><h2 className="px-1 text-xs font-bold uppercase tracking-wide text-slate-400">{title}</h2><div className="mt-2 space-y-1">{items.length ? items.map(item => <Link key={item.uuid} href={item.url} className={`flex min-w-0 items-center gap-3 rounded-2xl px-2.5 py-2.5 transition ${item.uuid === selectedUuid ? 'bg-blue-50 ring-1 ring-blue-100' : 'hover:bg-slate-50'}`}><ConversationAvatar conversation={item}/><div className="min-w-0 flex-1"><p className="truncate text-sm font-bold text-slate-800">{item.name}</p><p className="truncate text-xs text-slate-500">{preview(item)}</p></div><Icon name="arrow-right" className="h-4 w-4 shrink-0 text-slate-300"/></Link>) : <p className="px-2 py-3 text-sm text-slate-500">No {title.toLowerCase()} yet.</p>}</div></section>)}
        </div>
    </aside>;
}

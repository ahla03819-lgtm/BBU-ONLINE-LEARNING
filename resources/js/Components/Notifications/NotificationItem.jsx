import React, {useState} from 'react';
import {router} from '@inertiajs/react';
import Icon from '../UI/Icon';
import UserAvatar from '../UI/UserAvatar';

const tones = {indigo: 'bg-indigo-50 text-indigo-700 ring-indigo-100', blue: 'bg-sky-50 text-sky-700 ring-sky-100', green: 'bg-emerald-50 text-emerald-700 ring-emerald-100', amber: 'bg-amber-50 text-amber-700 ring-amber-100', red: 'bg-rose-50 text-rose-700 ring-rose-100'};

function detail(notification) {
    const context = notification.context || {};
    if (notification.type === 'conversation.direct-message') return context.actor_name ? `New message from ${context.actor_name}` : 'New direct message';
    if (notification.type === 'conversation.group-message') return context.conversation_name ? `New message in ${context.conversation_name}` : 'New group message';
    if (notification.type === 'conversation.call-missed') return context.actor_name ? `Missed ${context.call_type || ''} call from ${context.actor_name}`.replace('  ', ' ') : 'Missed call';
    if (notification.type === 'conversation.call-declined') return context.actor_name ? `${context.actor_name} declined your ${context.call_type || ''} call`.replace('  ', ' ') : 'Call declined';
    return context.title || context.assignment_title || context.conversation_name || context.message || null;
}

export function relativeTime(value) {
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000));
    if (seconds < 60) return 'Just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
    if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
    return new Date(value).toLocaleDateString();
}

export default function NotificationItem({notification, compact = false, onNavigate}) {
    const [marking, setMarking] = useState(false);
    const [error, setError] = useState('');
    const markRead = (after = null) => {
        if (notification.is_read) return after?.();
        if (marking) return;
        setError('');
        router.patch(`/notifications/${notification.public_id}/read`, {}, {preserveScroll: true, preserveState: true, onStart: () => setMarking(true), onError: () => setError('Unable to mark this activity as read.'), onSuccess: () => after?.(), onFinish: () => setMarking(false)});
    };
    const open = () => {
        if (!notification.href || marking) return;
        markRead(() => { onNavigate?.(); router.visit(notification.href); });
    };
    const itemDetail = detail(notification);
    const content = <>
        <span className={`mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ${tones[notification.tone] || tones.indigo}`}>{notification.actor ? <UserAvatar name={notification.actor.name} avatarUrl={notification.actor.avatar_url} size="sm" alt=""/> : <Icon name={notification.icon || 'bell'} className="h-4 w-4"/>}</span>
        <span className="min-w-0 flex-1"><span className="flex items-start justify-between gap-3"><span className="font-bold text-slate-900">{itemDetail || notification.label}</span>{!notification.is_read && <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-sky-700" aria-label="Unread"/>}</span>{itemDetail && itemDetail !== notification.label && <span className="mt-0.5 block truncate text-sm text-slate-500">{notification.label}</span>}<span className="mt-1 block text-xs font-medium text-slate-400" title={new Date(notification.created_at).toLocaleString()}>{relativeTime(notification.created_at)}</span></span>
    </>;

    return <article className={`rounded-2xl border transition ${notification.is_read ? 'border-slate-100 bg-white' : 'border-sky-100 bg-sky-50/45 shadow-sm'} ${compact ? 'p-2.5' : 'p-4'}`}>{notification.href ? <button type="button" onClick={open} className="flex w-full items-start gap-3 rounded-xl text-left focus:outline-none focus:ring-2 focus:ring-sky-600" disabled={marking}>{content}</button> : <div className="flex items-start gap-3">{content}</div>}{!compact && <div className="mt-3 flex items-center gap-3 pl-[3.25rem]">{!notification.is_read && <button type="button" className="text-sm font-bold text-sky-800 disabled:opacity-50" disabled={marking} onClick={() => markRead()}>{marking ? 'Marking…' : 'Mark as read'}</button>}{!notification.href && <span className="text-xs text-slate-500">Related content is no longer available.</span>}</div>}{error && <p className="mt-2 text-sm text-red-700" role="alert">{error}</p>}</article>;
}

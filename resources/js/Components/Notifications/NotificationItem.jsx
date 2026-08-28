import React, {useState} from 'react';
import {Link, router} from '@inertiajs/react';

function detail(notification) {
    return notification.context.title
        || notification.context.assignment_title
        || notification.context.message
        || null;
}

export default function NotificationItem({notification}) {
    const [marking, setMarking] = useState(false);
    const [error, setError] = useState('');
    const markRead = () => {
        if (notification.is_read || marking) return;
        setError('');
        router.patch(`/notifications/${notification.public_id}/read`, {}, {
            preserveScroll: true,
            onStart: () => setMarking(true),
            onError: () => setError('Unable to mark this notification as read.'),
            onFinish: () => setMarking(false),
        });
    };
    const content = <>
        <div className="flex items-start justify-between gap-4">
            <div>
                <p className="font-semibold text-slate-900">{notification.label}</p>
                {detail(notification) && <p className="mt-1 text-sm text-slate-600">{detail(notification)}</p>}
            </div>
            {!notification.is_read && <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-indigo-600" aria-label="Unread" />}
        </div>
        <p className="mt-2 text-xs text-slate-500">{new Date(notification.created_at).toLocaleString()}</p>
    </>;

    return <article className={`rounded-lg border p-4 ${notification.is_read ? 'bg-white' : 'border-indigo-200 bg-indigo-50'}`}>
        {notification.href ? <Link href={notification.href} className="block rounded focus:outline-none focus:ring-2 focus:ring-indigo-500">{content}</Link> : content}
        <div className="mt-3 flex items-center gap-3">
            {!notification.is_read && <button type="button" className="text-sm font-medium text-indigo-700 disabled:opacity-50" disabled={marking} onClick={markRead}>{marking ? 'Marking…' : 'Mark as read'}</button>}
            {!notification.href && <span className="text-xs text-slate-500">Related content is no longer available.</span>}
        </div>
        {error && <p className="mt-2 text-sm text-red-600" role="alert">{error}</p>}
    </article>;
}

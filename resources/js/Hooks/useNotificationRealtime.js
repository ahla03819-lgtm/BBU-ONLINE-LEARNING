import {useCallback, useEffect, useRef, useState} from 'react';
import {router} from '@inertiajs/react';
import {echo} from '../realtime/echo';

export default function useNotificationRealtime({userId, initialUnreadCount, enabled, inboxOpen}) {
    const [unreadCount, setUnreadCount] = useState(initialUnreadCount ?? 0);
    const reloading = useRef(false);
    const reloadRequested = useRef(false);
    const seen = useRef(new Set());

    useEffect(() => setUnreadCount(initialUnreadCount ?? 0), [initialUnreadCount]);

    const reconcile = useCallback(() => {
        if (reloading.current) {
            reloadRequested.current = true;
            return;
        }
        reloading.current = true;
        router.reload({
            only: inboxOpen ? ['notificationInbox', 'notifications'] : ['notificationInbox'],
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                reloading.current = false;
                if (reloadRequested.current) {
                    reloadRequested.current = false;
                    reconcile();
                }
            },
        });
    }, [inboxOpen]);

    useEffect(() => {
        if (!enabled || !userId || !echo) return undefined;
        const name = `notifications.${userId}`;
        const handleCreated = event => {
            const publicId = event?.notification?.public_id;
            if (publicId && seen.current.has(publicId)) return;
            if (publicId) {
                seen.current.add(publicId);
                if (seen.current.size > 100) seen.current.delete(seen.current.values().next().value);
            }
            if (Number.isInteger(event?.unread_count)) setUnreadCount(event.unread_count);
            reconcile();
        };
        const channel = echo.private(name).listen('.notification.created', handleCreated);
        const socket = echo.connector.pusher.connection;
        const connected = () => reconcile();
        const focused = () => reconcile();
        const visible = () => { if (document.visibilityState === 'visible') reconcile(); };
        socket.bind('connected', connected);
        window.addEventListener('focus', focused);
        document.addEventListener('visibilitychange', visible);

        return () => {
            socket.unbind('connected', connected);
            window.removeEventListener('focus', focused);
            document.removeEventListener('visibilitychange', visible);
            channel.stopListening('.notification.created', handleCreated);
            echo.leave(name);
        };
    }, [enabled, reconcile, userId]);

    return unreadCount;
}

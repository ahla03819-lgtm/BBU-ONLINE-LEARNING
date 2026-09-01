import React, {useState} from 'react';
import {Head, router, usePage} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import NotificationItem from '../../Components/Notifications/NotificationItem';
import NotificationPagination from '../../Components/Notifications/NotificationPagination';

export default function Index({notifications}) {
    const {notificationInbox} = usePage().props;
    const [markingAll, setMarkingAll] = useState(false);
    const [error, setError] = useState('');
    const markAll = () => {
        if (markingAll || notificationInbox.unread_count === 0) return;
        setError('');
        router.patch('/notifications/read-all', {}, {
            preserveScroll: true,
            onStart: () => setMarkingAll(true),
            onError: () => setError('Unable to mark notifications as read.'),
            onFinish: () => setMarkingAll(false),
        });
    };

    return <Layout>
        <Head title="Notifications" />
        <div className="mx-auto max-w-3xl">
            <div className="mb-6 flex items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-bold">Notifications</h1>
                    <p className="mt-1 text-sm text-slate-500">Important learning activity and updates.</p>
                </div>
                {notificationInbox.unread_count > 0 && <button type="button" className="btn" disabled={markingAll} onClick={markAll}>{markingAll ? 'Marking…' : 'Mark all as read'}</button>}
            </div>
            {error && <p className="mb-4 rounded bg-red-50 p-3 text-sm text-red-700" role="alert">{error}</p>}
            {notifications.data.length === 0
                ? <div className="card text-center text-slate-500"><h2 className="font-medium text-slate-700">No notifications yet</h2><p className="mt-1">Important updates will appear here.</p></div>
                : <div className="space-y-3">{notifications.data.map(notification => <NotificationItem key={notification.public_id} notification={notification} />)}</div>}
            <NotificationPagination links={notifications.links} />
        </div>
    </Layout>;
}

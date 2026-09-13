import React, {useMemo, useState} from 'react';
import {Head, router, usePage} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import NotificationItem from '../../Components/Notifications/NotificationItem';
import NotificationPagination from '../../Components/Notifications/NotificationPagination';
import UserAvatar from '../../Components/UI/UserAvatar';

const adminTones = {indigo: 'bg-indigo-50 text-indigo-700 ring-indigo-100', blue: 'bg-sky-50 text-sky-700 ring-sky-100', green: 'bg-emerald-50 text-emerald-700 ring-emerald-100', amber: 'bg-amber-50 text-amber-700 ring-amber-100'};
const formatActivityDate = value => new Intl.DateTimeFormat(undefined, {dateStyle: 'medium', timeStyle: 'short'}).format(new Date(value));

function AdminActivityItem({activity}) {
    const sentence = activity.actor ? `${activity.actor.name} ${activity.message}` : activity.fallback_message;
    const content = <><span className={`mt-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 ${adminTones[activity.tone] || adminTones.indigo}`}>{activity.actor ? <UserAvatar name={activity.actor.name} avatarUrl={activity.actor.avatar_url} size="sm" alt=""/> : <Icon name={activity.icon} className="h-4 w-4"/>}</span><span className="min-w-0 flex-1"><span className="block font-bold leading-6 text-slate-900">{sentence}</span>{activity.resource && <span className="mt-0.5 block truncate text-sm font-medium text-slate-600">{activity.resource}</span>}<span className="mt-1 block text-xs font-medium text-slate-400">{formatActivityDate(activity.created_at)}</span></span></>;
    return <article className="rounded-2xl border border-slate-100 bg-white p-4 shadow-sm">{activity.href ? <a href={activity.href} className="flex items-start gap-3 rounded-xl focus:outline-none focus:ring-2 focus:ring-sky-600">{content}</a> : <div className="flex items-start gap-3">{content}</div>}</article>;
}

export default function Index({notifications, adminActivity = null}) {
    const {notificationInbox} = usePage().props;
    const [filter, setFilter] = useState('all');
    const [markingAll, setMarkingAll] = useState(false);
    const [error, setError] = useState('');
    const [feed, setFeed] = useState('personal');
    const visible = useMemo(() => notifications.data.filter(notification => filter === 'all' || !notification.is_read), [filter, notifications.data]);
    const markAll = () => {
        if (markingAll || notificationInbox.unread_count === 0) return;
        setError('');
        router.patch('/notifications/read-all', {}, {preserveScroll: true, onStart: () => setMarkingAll(true), onError: () => setError('Unable to mark activity as read.'), onFinish: () => setMarkingAll(false)});
    };

    return <Layout>
        <Head title="Activity" />
        <div className="mx-auto max-w-4xl">
            <section className="rounded-3xl border border-sky-100 bg-gradient-to-br from-sky-50 via-white to-blue-50 p-5 shadow-sm sm:p-7">
                <div className="flex flex-wrap items-start justify-between gap-4"><div className="flex items-start gap-3"><span className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-sky-800 text-white shadow-lg shadow-sky-900/15"><Icon name="bell" className="h-5 w-5"/></span><div><h1 className="text-2xl font-black tracking-tight text-slate-900">Activity</h1><p className="mt-1 text-sm text-slate-600">{feed === 'admin' ? 'Administrative changes and operational activity across BBU ONLINE LEARNING.' : 'Private updates from your authorized learning spaces.'}</p></div></div>{feed === 'personal' && notificationInbox.unread_count > 0 && <button type="button" className="rounded-xl bg-sky-800 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-sky-900 disabled:opacity-50" disabled={markingAll} onClick={markAll}>{markingAll ? 'Marking…' : 'Mark all as read'}</button>}</div>
                <div className="mt-5 flex flex-wrap items-center gap-3"><div className="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm" aria-label="Activity feed"><button type="button" onClick={() => setFeed('personal')} className={`rounded-lg px-3.5 py-2 text-sm font-bold transition ${feed === 'personal' ? 'bg-sky-800 text-white' : 'text-slate-600 hover:bg-sky-50'}`}>My Activity</button>{adminActivity && <button type="button" onClick={() => setFeed('admin')} className={`rounded-lg px-3.5 py-2 text-sm font-bold transition ${feed === 'admin' ? 'bg-sky-800 text-white' : 'text-slate-600 hover:bg-sky-50'}`}>Admin Activity</button>}</div>{feed === 'personal' && <div className="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm" aria-label="Personal activity filters"><button type="button" onClick={() => setFilter('all')} className={`rounded-lg px-3.5 py-2 text-sm font-bold transition ${filter === 'all' ? 'bg-sky-800 text-white' : 'text-slate-600 hover:bg-sky-50'}`}>All</button><button type="button" onClick={() => setFilter('unread')} className={`rounded-lg px-3.5 py-2 text-sm font-bold transition ${filter === 'unread' ? 'bg-sky-800 text-white' : 'text-slate-600 hover:bg-sky-50'}`}>Unread{notificationInbox.unread_count ? ` (${notificationInbox.unread_count})` : ''}</button></div>}</div>
            </section>
            {error && <p className="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{error}</p>}
            {feed === 'personal' ? <><section className="mt-5 space-y-3">{visible.length ? visible.map(notification => <NotificationItem key={notification.public_id} notification={notification}/>) : <div className="rounded-3xl border border-dashed border-sky-200 bg-white px-6 py-14 text-center shadow-sm"><span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-sky-700"><Icon name="bell" className="h-5 w-5"/></span><h2 className="mt-4 font-bold text-slate-900">{filter === 'unread' ? 'You are all caught up' : 'No personal activity yet'}</h2><p className="mt-1 text-sm text-slate-500">{filter === 'unread' ? 'New updates will appear here when they arrive.' : 'Important updates from your learning spaces will appear here.'}</p></div>}</section>{filter === 'all' && <NotificationPagination links={notifications.links}/>}</> : <><section className="mt-5 space-y-3">{adminActivity.data.length ? adminActivity.data.map(activity => <AdminActivityItem key={activity.id} activity={activity}/>) : <div className="rounded-3xl border border-dashed border-sky-200 bg-white px-6 py-14 text-center shadow-sm"><span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-50 text-sky-700"><Icon name="settings" className="h-5 w-5"/></span><h2 className="mt-4 font-bold text-slate-900">No administrative activity yet</h2><p className="mt-1 text-sm text-slate-500">Authorized operational updates will appear here.</p></div>}</section><NotificationPagination links={adminActivity.links}/></>}
        </div>
    </Layout>;
}

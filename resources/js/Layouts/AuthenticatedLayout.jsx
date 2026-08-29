import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import useNotificationRealtime from '../Hooks/useNotificationRealtime';

export default function AuthenticatedLayout({ children }) {
    const page = usePage();
    const { auth, flash, notificationInbox } = page.props;
    const canViewUsers = auth.permissions.includes('users.view');
    const canViewNotifications = auth.permissions.includes('notifications.view');
    const canManageAttendance = auth.permissions.includes('attendance.view');
    const canViewOwnAttendance = auth.permissions.includes('attendance.view-own');
    const unreadCount = useNotificationRealtime({userId: auth.user.id, initialUnreadCount: notificationInbox?.unread_count, enabled: canViewNotifications, inboxOpen: page.component === 'Notifications/Index'});
    return <div className="min-h-screen"><header className="border-b bg-white"><div className="mx-auto flex max-w-7xl items-center gap-6 px-6 py-4"><Link href="/dashboard" className="font-bold">EDWAY School</Link>{canViewUsers && <Link href="/users">Users</Link>}{auth.permissions.includes('academic-years.view')&&<Link href="/academics">Academics</Link>}{auth.permissions.includes('students.view')&&<Link href="/people">People</Link>}{auth.permissions.includes('channels.view')&&<Link href="/collaboration">Collaboration</Link>}{canManageAttendance&&<Link href="/attendance">Attendance</Link>}{canViewOwnAttendance&&<Link href="/my-attendance">My Attendance</Link>}{canViewNotifications&&<Link href="/notifications" className="relative">Notifications{unreadCount>0&&<span className="ml-1 inline-flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-semibold text-white" aria-label={`${unreadCount} unread notifications`}>{unreadCount>99?'99+':unreadCount}</span>}</Link>}<span className="ml-auto text-sm">{auth.user.name} · {auth.roles.join(', ')}</span><Link href="/logout" method="post" as="button" className="text-sm text-red-600">Logout</Link></div></header><main className="mx-auto max-w-7xl p-6">{flash.success && <div className="mb-4 rounded bg-green-100 p-3 text-green-800">{flash.success}</div>}{children}</main></div>;
}

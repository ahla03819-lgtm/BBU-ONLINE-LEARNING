import React, {useEffect, useMemo, useState} from 'react';
import {usePage} from '@inertiajs/react';
import useNotificationRealtime from '../Hooks/useNotificationRealtime';
import AppSidebar from '../Components/UI/AppSidebar';
import AppTopbar from '../Components/UI/AppTopbar';

export default function AuthenticatedLayout({ children }) {
    const page = usePage();
    const { auth, flash, notificationInbox } = page.props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const canViewUsers = auth.permissions.includes('users.view');
    const canViewNotifications = auth.permissions.includes('notifications.view');
    const canManageAttendance = auth.permissions.includes('attendance.view');
    const canViewOwnAttendance = auth.permissions.includes('attendance.view-own');
    const canViewResults = auth.permissions.includes('results.view');
    const canViewOwnResults = auth.permissions.includes('results.view-own');
    const unreadCount = useNotificationRealtime({userId: auth.user.id, initialUnreadCount: notificationInbox?.unread_count, enabled: canViewNotifications, inboxOpen: page.component === 'Notifications/Index'});
    const navigation = useMemo(() => [
        {label: 'Workspace', items: [{label: 'Dashboard', href: '/dashboard', component: 'Dashboard', icon: 'home'}]},
        {label: 'Academic', items: [{label: 'Academic foundation', href: auth.permissions.includes('academic-years.view') ? '/academics' : null, component: 'Academics/Index', icon: 'school'}, {label: 'Students & teachers', href: auth.permissions.includes('students.view') ? '/people' : null, component: 'People/Index', icon: 'users'}, {label: 'Classes', href: auth.permissions.includes('academic-years.view') ? '/academics' : null, component: 'Academics/Index', icon: 'book'}]},
        {label: 'Learning', items: [{label: 'Attendance', href: canManageAttendance ? '/attendance' : canViewOwnAttendance ? '/my-attendance' : null, component: canManageAttendance ? 'Attendance/Index' : 'Attendance/MyAttendance', icon: 'calendar'}, {label: canViewOwnResults ? 'My Results' : 'Results', href: canViewOwnResults ? '/my-results' : canViewResults ? '/results' : null, component: canViewOwnResults ? 'Results/MyResults' : 'Results/Index', icon: 'chart'}, {label: 'Coursework', href: null, icon: 'clipboard', visible: auth.permissions.includes('assignments.view')}, {label: 'Meetings', href: null, icon: 'video', visible: auth.permissions.includes('meetings.view')}]},
        {label: 'Communication', items: [{label: 'Collaboration', href: auth.permissions.includes('channels.view') ? '/collaboration' : null, component: 'Collaboration/Index', icon: 'messages'}, {label: 'Notifications', href: canViewNotifications ? '/notifications' : null, component: 'Notifications/Index', icon: 'bell', badge: unreadCount}]},
    ].map((group) => ({...group, items: group.items.filter((item) => item.href || item.visible)})).filter((group) => group.items.length), [auth.permissions, canManageAttendance, canViewOwnAttendance, canViewOwnResults, canViewResults, canViewNotifications, unreadCount]);
    useEffect(() => { const close = (event) => event.key === 'Escape' && setSidebarOpen(false); window.addEventListener('keydown', close); return () => window.removeEventListener('keydown', close); }, []);
    const title = page.component.split('/').pop().replace(/([A-Z])/g, ' $1').trim() || 'EDWAY';
    return <div className="min-h-screen"><AppSidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} navigation={navigation} user={auth.user} roles={auth.roles}/><div className="min-h-screen lg:pl-56"><AppTopbar title={title} onMenu={() => setSidebarOpen(true)} notificationUrl={canViewNotifications ? '/notifications' : null} unreadCount={unreadCount} user={auth.user}/><main className="mx-auto max-w-[1440px] p-4 sm:p-5 lg:p-6">{flash.success && <div role="status" className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">{flash.success}</div>}{children}</main></div></div>;
}

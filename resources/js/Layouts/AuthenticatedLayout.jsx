import React, {useEffect, useMemo, useState} from 'react';
import {router, usePage} from '@inertiajs/react';
import useNotificationRealtime from '../Hooks/useNotificationRealtime';
import AppSidebar from '../Components/UI/AppSidebar';
import AppTopbar from '../Components/UI/AppTopbar';
import {useAppSounds} from '../Sound/AppSounds';
import UserAvatar from '../Components/UI/UserAvatar';
import AnnouncementPopup, {clearAnnouncementSession} from '../Components/Announcements/AnnouncementPopup';
import {usePersistentMeeting} from '../Providers/PersistentMeetingProvider';
import {usePersistentConversationCall} from '../Providers/PersistentConversationCallProvider';

export default function AuthenticatedLayout({ children }) {
    const page = usePage();
    const { auth, flash, notificationInbox } = page.props;
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const [desktopSidebarOpen, setDesktopSidebarOpen] = useState(true);

    const canViewUsers = auth.permissions.includes('users.view');
    const canViewNotifications = auth.permissions.includes('notifications.view');
    const canManageAttendance = auth.permissions.includes('attendance.view');
    const canViewOwnAttendance = auth.permissions.includes('attendance.view-own');
    const canViewResults = auth.permissions.includes('results.view');
    const canViewOwnResults = auth.permissions.includes('results.view-own');
    const canManageAcademics = auth.permissions.includes('academic-years.create') || auth.permissions.includes('grade-levels.create') || auth.permissions.includes('subjects.create');
    const canManagePeople = auth.permissions.includes('students.manage') || auth.permissions.includes('teachers.manage');

    const sounds = useAppSounds();
    const {incomingCall, outgoingCall, acceptIncomingCall, declineIncomingCall, cancelOutgoingCall} = usePersistentConversationCall();
    const {activeMeeting} = usePersistentMeeting();
    const roomPath = activeMeeting && new URL(activeMeeting.roomUrl, window.location.origin).pathname;
    const unreadCount = useNotificationRealtime({
        userId: auth.user.id,
        initialUnreadCount: notificationInbox?.unread_count,
        enabled: canViewNotifications,
        inboxOpen: page.component === 'Notifications/Index',
        onNewNotification: (notification) => sounds.play('notification', notification.public_id),
        pauseBackgroundRefresh: Boolean(roomPath && window.location.pathname === roomPath),
    });

    const handleAccept = async (audioOnly = false) => {
        if (!incomingCall) return;
        await acceptIncomingCall(incomingCall, {audioOnly});
    };

    const handleDecline = async () => {
        if (!incomingCall) return;
        await declineIncomingCall(incomingCall);
    };

    const navigation = useMemo(() => [
        {label: 'Workspace', items: [{label: 'Dashboard', href: '/dashboard', component: 'Dashboard', icon: 'home'}, {label: 'Classes', href: auth.permissions.includes('classes.view') ? '/classes' : null, component: 'Classes/Index', icon: 'school'}]},
        {label: 'Learning', items: [{label: 'Attendance', href: canManageAttendance ? '/attendance' : canViewOwnAttendance ? '/my-attendance' : null, component: canManageAttendance ? 'Attendance/Index' : 'Attendance/MyAttendance', icon: 'calendar'}, {label: canViewOwnResults ? 'My Results' : 'Results', href: canViewOwnResults ? '/my-results' : canViewResults ? '/results' : null, component: canViewOwnResults ? 'Results/MyResults' : 'Results/Index', icon: 'chart'}, {label: 'Coursework', href: null, icon: 'clipboard', visible: auth.permissions.includes('assignments.view')}, {label: 'Calendar', href: auth.permissions.includes('meetings.view') || auth.permissions.includes('assignments.view') ? '/calendar' : null, component: 'Calendar/Index', icon: 'calendar'}, {label: 'Meetings', href: null, icon: 'video', visible: auth.permissions.includes('meetings.view')}]},
        {label: 'Communication', items: [{label: 'Collaboration', href: auth.permissions.includes('channels.view') ? '/collaboration' : null, component: 'Collaboration/Index', icon: 'messages'}, {label: 'Chats', href: auth.permissions.includes('classes.view') ? '/conversations' : null, component: 'Conversations/Index', icon: 'messages'}, {label: 'Notifications', href: canViewNotifications ? '/notifications' : null, component: 'Notifications/Index', icon: 'bell', badge: unreadCount}]},
        {label: 'Administration', items: [{label: 'Academic settings', href: canManageAcademics ? '/academics' : null, component: 'Academics/Index', icon: 'settings'}, {label: 'Students & teachers', href: canManagePeople ? '/people' : null, component: 'People/Index', icon: 'users'}, {label: 'Users & permissions', href: canViewUsers ? '/users' : null, component: 'Users/Index', icon: 'users'}]},
    ].map((group) => ({...group, items: group.items.filter((item) => item.href || item.visible)})).filter((group) => group.items.length), [auth.permissions, canManageAcademics, canManagePeople, canManageAttendance, canViewOwnAttendance, canViewOwnResults, canViewResults, canViewNotifications, unreadCount]);

    useEffect(() => {
        const close = (event) => event.key === 'Escape' && setSidebarOpen(false);
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, []);

    useEffect(() => {
        const removeBeforeListener = router.on('before', (event) => {
            const visit = event.detail.visit;

            if (visit.method === 'post' && String(visit.url).endsWith('/logout')) {
                clearAnnouncementSession(auth.user.id);
            }
        });

        return removeBeforeListener;
    }, [auth.user.id]);

    const title = page.component === 'MyAccount/Show'
        ? 'My Account'
        : (page.component.split('/').pop().replace(/([A-Z])/g, ' $1').trim() || 'BBU ONLINE LEARNING');

    const incomingInitiator = incomingCall?.initiator ?? {};
    const outgoingInitiator = outgoingCall?.initiator ?? {};
    const incomingCallerName = incomingInitiator.name ?? 'Caller';
    const outgoingCallerName = outgoingInitiator.name ?? 'Caller';

    const renderIncomingCall = incomingCall && (
        <div className="fixed inset-0 z-[80] flex items-center justify-center bg-slate-950/45 p-4">
            <section role="dialog" aria-modal="true" className="w-full max-w-sm rounded-3xl bg-white p-6 text-center shadow-2xl">
                <UserAvatar name={incomingCallerName} avatarUrl={incomingInitiator.avatar_url} size="lg" className="mx-auto"/>
                <p className="mt-4 text-sm text-slate-500">Incoming {incomingCall.type ?? 'audio'} call</p>
                <h2 className="mt-1 text-xl font-bold text-slate-900">{incomingCallerName}</h2>
                <p className="mt-1 text-sm text-slate-600">{incomingCall.name}</p>
                <div className="mt-6 flex justify-center gap-3">
                    <button onClick={handleDecline} className="rounded-xl border border-rose-200 px-4 py-2.5 font-bold text-rose-700">Decline</button>
                    {incomingCall.type === 'video' ? (
                        <>
                            <button onClick={() => handleAccept(false)} className="rounded-xl bg-blue-800 px-4 py-2.5 font-bold text-white">Accept</button>
                            <button onClick={() => handleAccept(true)} className="rounded-xl border border-slate-200 bg-slate-100 px-4 py-2.5 font-bold text-slate-700">Audio only</button>
                        </>
                    ) : (
                        <button onClick={() => handleAccept(false)} className="rounded-xl bg-blue-800 px-4 py-2.5 font-bold text-white">Accept</button>
                    )}
                </div>
            </section>
        </div>
    );

    const renderOutgoingCall = outgoingCall && (
        <div className="fixed bottom-5 right-5 z-[75] w-[min(22rem,calc(100vw-2rem))] rounded-3xl border border-blue-200 bg-white p-4 shadow-2xl shadow-slate-300/50">
            <div className="flex items-center gap-3">
                <UserAvatar name={outgoingCallerName} avatarUrl={outgoingInitiator.avatar_url} size="md"/>
                <div className="min-w-0 flex-1">
                    <p className="text-xs font-bold uppercase tracking-[0.2em] text-sky-600">{outgoingCall.type === 'video' ? 'Video call' : 'Audio call'}</p>
                    <h3 className="truncate text-base font-bold text-slate-900">{outgoingCallerName}</h3>
                    <p className="text-sm text-slate-600">Calling…</p>
                </div>
                <button type="button" onClick={() => cancelOutgoingCall(outgoingCall)} className="rounded-xl border border-rose-200 px-3 py-2 text-sm font-bold text-rose-700">Cancel</button>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen">
            <AppSidebar open={sidebarOpen} desktopOpen={desktopSidebarOpen} onClose={() => setSidebarOpen(false)} onDesktopToggle={() => setDesktopSidebarOpen((open) => !open)} navigation={navigation} user={auth.user} roleLabel={auth.role_label}/>
            <div className={`min-h-screen transition-[padding] duration-200 ease-out ${desktopSidebarOpen ? 'lg:pl-56' : 'lg:pl-0'}`}>
                <AppTopbar title={title} onMenu={() => setSidebarOpen(true)} onSidebarToggle={() => setDesktopSidebarOpen((open) => !open)} sidebarExpanded={desktopSidebarOpen} notificationUrl={canViewNotifications ? '/notifications' : null} unreadCount={unreadCount} notificationPreview={notificationInbox?.preview || []} user={auth.user}/>
                <main className="mx-auto max-w-[1440px] p-4 sm:p-5 lg:p-6">
                    {flash.success && <div role="status" className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800">{flash.success}</div>}
                    {children}
                </main>
            </div>
            {renderIncomingCall}
            {renderOutgoingCall}
            <AnnouncementPopup userId={auth.user.id}/>
        </div>
    );
}

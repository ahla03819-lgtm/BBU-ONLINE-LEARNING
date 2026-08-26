import React from 'react';
import {Head, Link, router, usePage} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import ChannelSidebar from '../../Components/Collaboration/ChannelSidebar';
import ChannelManager from '../../Components/Collaboration/ChannelManager';
import AnnouncementList from '../../Components/Collaboration/AnnouncementList';
import MessagingPanel from '../../Components/Collaboration/Messaging/MessagingPanel';

export default function Workspace({schoolClass, channels, channel, announcements, featuredMeeting, canViewMeetings, canCreateChannel, canCreateAnnouncement, canCreateMessage, canModerateMessages, isAdministrator}) {
    const auth = usePage().props.auth; const currentUser = auth.user;
    return <Layout>
        <Head title={`${schoolClass.name} collaboration`}/>
        <div className="mb-6"><div className="flex items-center justify-between"><Link className="text-sm text-indigo-600" href="/collaboration">← All classes</Link>{canViewMeetings&&<Link className="text-sm font-medium text-indigo-600" href={`/school-classes/${schoolClass.id}/meetings`}>Meetings</Link>}</div><h1 className="mt-2 text-2xl font-bold">{schoolClass.name} {schoolClass.section}</h1><p className="text-slate-500">{schoolClass.grade_level.name} · {schoolClass.academic_year.name}</p></div>
        {featuredMeeting&&<section className={`mb-6 rounded-xl border p-4 ${featuredMeeting.status==='active'?'border-emerald-300 bg-emerald-50':'border-slate-200 bg-white'}`} aria-label="Featured meeting"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{featuredMeeting.status==='active'?'Live now':'Upcoming meeting'}</p><h2 className="text-lg font-semibold">{featuredMeeting.title}</h2><p className="text-sm text-slate-600">{featuredMeeting.subject?`${featuredMeeting.subject.code} · ${featuredMeeting.subject.name}`:'General class meeting'}{featuredMeeting.host?` · Host: ${featuredMeeting.host.name}`:''}</p></div><div className="flex gap-2"><Link className="btn" href={`/school-classes/${schoolClass.id}/meetings/${featuredMeeting.uuid}`}>View meeting</Link>{featuredMeeting.can_join&&featuredMeeting.status==='active'&&<Link className="btn" href={`/collaboration/classes/${schoolClass.id}/meetings/${featuredMeeting.uuid}/lobby`}>Join meeting</Link>}{featuredMeeting.can_start&&featuredMeeting.status==='scheduled'&&<button className="btn" onClick={()=>router.post(`/school-classes/${schoolClass.id}/meetings/${featuredMeeting.uuid}/start`)}>Start meeting</button>}</div></div></section>}
        <div className="grid gap-6 lg:grid-cols-[240px_1fr_300px]">
            <ChannelSidebar schoolClass={schoolClass} channels={channels} selected={channel}/>
            <main><div className="mb-4 flex items-center justify-between"><div><h2 className="text-xl font-semibold"># {channel?.name || 'No channel'}</h2><p className="text-sm text-slate-500">{channel?.description}</p></div>{canCreateAnnouncement && <Link className="btn" href={`/collaboration/classes/${schoolClass.id}/channels/${channel.id}/announcements/create`}>New announcement</Link>}</div>
                {channel && ['announcement', 'subject'].includes(channel.type) && <AnnouncementList schoolClass={schoolClass} channel={channel} announcements={announcements} isAdministrator={isAdministrator}/>}
                {channel && <MessagingPanel key={channel.id} schoolClass={schoolClass} channel={channel} currentUser={currentUser} canCreate={canCreateMessage} canModerate={canModerateMessages} canUpload={auth.permissions.includes('attachments.upload')} canReact={canCreateMessage && auth.permissions.includes('reactions.create')}/>}
            </main>
            <ChannelManager schoolClass={schoolClass} channel={channel} canCreate={canCreateChannel}/>
        </div>
    </Layout>;
}

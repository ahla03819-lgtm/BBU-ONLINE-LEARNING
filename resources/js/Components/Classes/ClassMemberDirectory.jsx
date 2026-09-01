import React from 'react';
import {Link, router, useForm} from '@inertiajs/react';
import Icon from '../UI/Icon';
import UserAvatar from '../UI/UserAvatar';

export default function ClassMemberDirectory({schoolClass, members, search}) {
    const form = useForm({search: search || ''});

    const submit = (event) => {
        event.preventDefault();
        form.get(`/classes/${schoolClass.id}/members`, {preserveState: true, replace: true});
    };

    return <>
        <section className="bbu-page-hero rounded-3xl px-6 py-7 sm:px-8"><div className="flex flex-wrap items-start justify-between gap-5"><div className="flex items-start gap-4"><span className="bbu-primary-icon inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-white"><Icon name="users" className="h-6 w-6"/></span><div><p className="text-sm font-bold text-sky-700">Class workspace</p><h1 className="mt-1 text-3xl font-bold tracking-tight text-slate-900">Members</h1><p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">People currently assigned or enrolled in {schoolClass.name}{schoolClass.section ? ` ${schoolClass.section}` : ''}.</p></div></div><Link href={schoolClass.workspaceUrl} className="inline-flex min-h-10 items-center gap-2 rounded-xl border border-sky-200 bg-white px-3.5 py-2 text-sm font-bold text-sky-800 transition hover:bg-sky-50 focus:outline-none focus:ring-2 focus:ring-sky-600"><Icon name="arrow-left" className="h-4 w-4"/>Workspace</Link></div></section>
        <section className="mt-6 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6"><div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"><div><h2 className="text-lg font-bold text-slate-900">Class directory</h2><p className="mt-1 text-sm text-slate-500">{members.length} {members.length === 1 ? 'member' : 'members'} shown from this class only.</p></div><form onSubmit={submit} className="w-full sm:max-w-sm"><label htmlFor="member-search" className="sr-only">Search class members</label><div className="relative"><Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"/><input id="member-search" className="field field-with-leading-icon field-with-trailing-action" value={form.data.search} onChange={(event) => form.setData('search', event.target.value)} placeholder="Search members" maxLength="100"/><button className="absolute right-1.5 top-1/2 -translate-y-1/2 rounded-lg bg-sky-700 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600 disabled:opacity-50" disabled={form.processing}>Search</button></div></form></div>
            {members.length ? <div className="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">{members.map((member) => <MemberCard key={`${member.role}-${member.id}`} member={member}/>)}</div> : <div className="mt-5 rounded-2xl border border-dashed border-sky-200 bg-sky-50/55 px-5 py-10 text-center"><span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-sky-700 shadow-sm"><Icon name="users" className="h-6 w-6"/></span><h3 className="mt-4 font-bold text-slate-900">No class members found</h3><p className="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-600">Try another name. Search is limited to people assigned or enrolled in this class.</p></div>}
        </section>
        <aside className="mt-4 rounded-2xl border border-sky-100 bg-sky-50/60 px-4 py-3 text-sm leading-6 text-slate-600"><span className="font-bold text-sky-900">Private chats:</span> chat is available only with people currently in this class. Audio and video calls are not part of this workspace.</aside>
    </>;
}

function MemberCard({member}) {
    return <article className="flex min-w-0 items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/60 p-4 transition hover:border-sky-200 hover:bg-white hover:shadow-sm"><UserAvatar name={member.name} avatarUrl={member.avatarUrl} alt={`${member.name}'s profile photo`} size="md"/><div className="min-w-0 flex-1"><div className="flex min-w-0 items-center gap-2"><h3 className="truncate font-bold text-slate-900">{member.name}</h3>{member.isCurrentUser && <span className="shrink-0 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-800">You</span>}</div><p className="mt-0.5 text-sm text-slate-500">{member.role}</p></div>{member.chatUrl && <button type="button" onClick={() => router.post(member.chatUrl)} className="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-sky-200 bg-white px-2.5 py-1.5 text-xs font-bold text-sky-800 hover:bg-sky-50"><Icon name="messages" className="h-3.5 w-3.5"/>Chat</button>}</article>;
}

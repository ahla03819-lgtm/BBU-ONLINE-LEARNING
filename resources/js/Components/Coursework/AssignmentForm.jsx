import React from 'react';
import {useForm} from '@inertiajs/react';

export default function AssignmentForm({action, method='post', assignment=null}) {
    const form=useForm({title:assignment?.title||'',instructions:assignment?.instructions||'',max_points:assignment?.max_points||100,due_at:assignment?.due_at?.slice(0,16)||'',allow_resubmission:assignment?.allow_resubmission??true,lifecycle_version:assignment?.lifecycle_version??0});
    const submit=e=>{e.preventDefault();form.transform(data=>assignment?.status==='published'?{title:data.title,instructions:data.instructions,due_at:data.due_at,lifecycle_version:data.lifecycle_version}:data)[method](action)};
    return <form onSubmit={submit} className="card space-y-4">
        <div><label className="label">Title</label><input className="input" value={form.data.title} onChange={e=>form.setData('title',e.target.value)}/><p className="text-red-600">{form.errors.title}</p></div>
        <div><label className="label">Instructions</label><textarea className="input min-h-40" value={form.data.instructions} onChange={e=>form.setData('instructions',e.target.value)}/></div>
        <div className="grid gap-4 md:grid-cols-2"><div><label className="label">Maximum points</label><input className="input" type="number" min="0.01" step="0.01" disabled={assignment?.status==='published'} value={form.data.max_points} onChange={e=>form.setData('max_points',e.target.value)}/><p className="text-red-600">{form.errors.max_points}</p></div><div><label className="label">Due date</label><input className="input" type="datetime-local" value={form.data.due_at} onChange={e=>form.setData('due_at',e.target.value)}/></div></div>
        {assignment?.status!=='published'&&<label className="flex gap-2"><input type="checkbox" checked={form.data.allow_resubmission} onChange={e=>form.setData('allow_resubmission',e.target.checked)}/> Allow resubmission</label>}
        <button className="btn" disabled={form.processing}>Save assignment</button>
    </form>;
}

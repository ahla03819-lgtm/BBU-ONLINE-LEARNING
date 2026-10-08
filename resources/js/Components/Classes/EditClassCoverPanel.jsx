import React from 'react';
import {useForm} from '@inertiajs/react';

export default function EditClassCoverPanel({schoolClass}) {
    const form = useForm({cover: null});
    const submit = (event) => { event.preventDefault(); form.post(`/classes/${schoolClass.id}/cover`, {forceFormData: true, preserveScroll: true}); };

    return <form className="flex flex-wrap items-center gap-2 bg-sky-50/50 p-3" onSubmit={submit}>
        <label className="text-xs font-bold text-sky-900">Change cover<input className="ml-2 max-w-48 rounded text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-600 focus-visible:ring-offset-2" type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => form.setData('cover', event.target.files?.[0] || null)}/></label>
        <button className="rounded-lg bg-sky-700 px-3 py-1.5 text-xs font-bold text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sky-600 focus-visible:ring-offset-2 disabled:opacity-50" disabled={!form.data.cover || form.processing}>{form.processing ? 'Uploading…' : 'Upload cover'}</button>
        {schoolClass.coverImageUrl && <button type="button" className="rounded-lg px-2 py-1.5 text-xs font-bold text-rose-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2" onClick={() => form.delete(`/classes/${schoolClass.id}/cover`, {preserveScroll: true})}>Remove</button>}
        {form.errors.cover && <p className="w-full text-xs font-medium text-rose-700" role="alert">{form.errors.cover}</p>}
    </form>;
}

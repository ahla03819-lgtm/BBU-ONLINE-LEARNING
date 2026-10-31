import React from 'react';
import AuthenticatedLayout from '../../Layouts/AuthenticatedLayout';
import {Head, router} from '@inertiajs/react';

export default function Trash({recordings}) {
    const restore = (uuid) => {
        if (!confirm('Restore this recording?')) return;
        router.post(`/recordings/${uuid}/restore`, {}, {preserveScroll: true});
    };
    return (
        <AuthenticatedLayout>
            <Head title="Recording Trash" />
            <div className="mx-auto max-w-6xl px-4 py-6">
                <h1 className="text-xl font-semibold">Recording Trash</h1>
                <p className="mt-1 text-sm text-slate-600">Trashed recordings remain available for authorized restoration. Permanent purge is currently unavailable.</p>
                <div className="mt-4 overflow-x-auto">
                    <table className="min-w-full divide-y divide-slate-200">
                        <thead>
                            <tr>
                                <th className="px-3 py-2 text-left text-xs font-medium">Title</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Class/Meeting</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Recorded</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Deleted</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Deleted By</th>
                                <th className="px-3 py-2 text-left text-xs font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recordings?.data?.map(r => (
                                <tr key={r.public_uuid} className="border-b">
                                    <td className="px-3 py-2 text-sm">{r.original_name || r.public_uuid}</td>
                                    <td className="px-3 py-2 text-sm">{r.meeting?.school_class?.name} / {r.meeting?.title}</td>
                                    <td className="px-3 py-2 text-sm">{r.ready_at || r.started_at}</td>
                                    <td className="px-3 py-2 text-sm">{r.deleted_at}</td>
                                    <td className="px-3 py-2 text-sm">{r.deleted_by?.name}</td>
                                    <td className="px-3 py-2 text-sm">{r.can_restore && <button className="text-blue-600" onClick={() => restore(r.public_uuid)}>Restore</button>}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}

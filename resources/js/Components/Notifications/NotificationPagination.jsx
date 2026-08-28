import React from 'react';
import {Link} from '@inertiajs/react';

function label(value) {
    return value.replace('&laquo; Previous', 'Previous').replace('Next &raquo;', 'Next');
}

export default function NotificationPagination({links}) {
    if (!links || links.length <= 3) return null;

    return <nav className="mt-6 flex flex-wrap gap-2" aria-label="Notification pages">
        {links.map((link, index) => link.url
            ? <Link key={index} href={link.url} preserveScroll className={`rounded border px-3 py-2 text-sm ${link.active ? 'border-indigo-600 bg-indigo-600 text-white' : 'bg-white text-slate-700'}`}>{label(link.label)}</Link>
            : <span key={index} className="rounded border px-3 py-2 text-sm text-slate-400">{label(link.label)}</span>)}
    </nav>;
}

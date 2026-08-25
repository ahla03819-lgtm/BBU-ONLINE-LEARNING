import React from 'react';
export default function AnnouncementStatusBadge({status}){const colors={published:'bg-green-100 text-green-800',scheduled:'bg-blue-100 text-blue-800',draft:'bg-slate-100 text-slate-700',archived:'bg-amber-100 text-amber-800'};return <span className={`rounded-full px-2 py-1 text-xs ${colors[status]||colors.draft}`}>{status}</span>}

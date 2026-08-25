import React from 'react';

export default function PresenceList({members}) {
    return <section className="card p-4"><h3 className="font-semibold">Online now</h3><ul className="mt-2 space-y-1 text-sm">{members.map(member => <li key={member.id}><span className="mr-2 text-emerald-500">●</span>{member.name}<span className="ml-1 text-xs text-slate-400">{member.participant_type}</span></li>)}</ul>{!members.length && <p className="mt-2 text-sm text-slate-400">Presence unavailable.</p>}</section>;
}

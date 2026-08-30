import React from 'react';

export default function EmptyState({text}) { return <div className="rounded-xl border border-dashed border-indigo-100 bg-indigo-50/40 p-4"><span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-white text-indigo-500 shadow-sm" aria-hidden="true">✦</span><p className="mt-2 text-sm leading-6 text-slate-500">{text}</p></div>; }

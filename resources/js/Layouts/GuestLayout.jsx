import React from 'react';

export default function GuestLayout({ title, children }) {
    return <main className="grid min-h-screen place-items-center p-6"><section className="card w-full max-w-md"><h1 className="mb-1 text-2xl font-bold">EDWAY School</h1><p className="mb-6 text-slate-500">{title}</p>{children}</section></main>;
}

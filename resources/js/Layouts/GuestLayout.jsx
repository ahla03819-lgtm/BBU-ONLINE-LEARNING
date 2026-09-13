import React from 'react';
import bbuOfficialLogo from '../assets/bbu-official-logo.png';
import bbuCampusNight from '../assets/bbu-campus-night.png';

export default function GuestLayout({title, children}) {
    return <main className="relative grid min-h-screen place-items-center overflow-hidden bg-[#061b3a] px-4 py-7 sm:px-6" style={{backgroundImage: `url(${bbuCampusNight})`, backgroundPosition: 'center', backgroundRepeat: 'no-repeat', backgroundSize: 'cover'}}>
        <div aria-hidden="true" className="absolute inset-0 bg-[linear-gradient(110deg,rgba(3,20,50,.72),rgba(5,38,88,.44)_48%,rgba(2,14,37,.68))]"/>
        <div aria-hidden="true" className="absolute inset-x-0 bottom-0 h-1/3 bg-gradient-to-t from-[#020b1d]/48 to-transparent"/>
        <section className="relative z-10 w-full max-w-[27rem] self-center rounded-[1.75rem] border border-white/60 bg-white/[.94] p-6 shadow-[0_26px_70px_rgba(0,0,0,.36)] backdrop-blur-md sm:p-8" aria-label={title}>
            <div className="mb-7 flex flex-col items-center text-center"><img src={bbuOfficialLogo} alt="Build Bright University" className="h-20 w-16 object-contain drop-shadow-sm"/><p className="mt-2 text-2xl font-extrabold tracking-tight text-[#082f63]">BBU</p><p className="text-[10px] font-bold tracking-[.18em] text-[#075ca8]">ONLINE LEARNING</p><h1 className="mt-6 text-2xl font-bold tracking-tight text-slate-900">{title}</h1><p className="mt-2 text-sm leading-6 text-slate-600">Sign in to continue to BBU ONLINE LEARNING.</p></div>
            {children}
        </section>
    </main>;
}

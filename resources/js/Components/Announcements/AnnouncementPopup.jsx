import React, {useEffect, useState} from 'react';
import announcementImage from '../../assets/bbu-announcement-2026.png';

const announcementVersion = '2026';
const DISPLAY_DURATION = 6000;
const ENTER_DURATION = 350;
const EXIT_DURATION = 350;
const ANIMATION_FRAME_DELAY = 20;

function storageKey(userId) {
    return `bbu.announcement.${announcementVersion}.shown.${userId}`;
}

export function clearAnnouncementSession(userId) {
    if (!userId || typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.removeItem(storageKey(userId));
    } catch {
        // Storage denial must never affect logout or authenticated navigation.
    }
}

export default function AnnouncementPopup({userId}) {
    const key = storageKey(userId);
    const [phase, setPhase] = useState('hidden');

    useEffect(() => {
        if (!userId || typeof window === 'undefined') {
            setPhase('hidden');

            return undefined;
        }

        try {
            if (window.sessionStorage.getItem(key) === 'true') {
                setPhase('hidden');

                return undefined;
            }

            window.sessionStorage.setItem(key, 'true');
        } catch {
            // The announcement remains optional if session storage is unavailable.
        }

        setPhase('enter');
        const enterTimer = window.setTimeout(() => setPhase('visible'), ANIMATION_FRAME_DELAY);
        const exitTimer = window.setTimeout(() => setPhase('exiting'), DISPLAY_DURATION);
        const hideTimer = window.setTimeout(() => setPhase('hidden'), DISPLAY_DURATION + EXIT_DURATION);

        return () => {
            window.clearTimeout(enterTimer);
            window.clearTimeout(exitTimer);
            window.clearTimeout(hideTimer);
        };
    }, [key, userId]);

    if (phase === 'hidden') {
        return null;
    }

    const isVisible = phase === 'visible';

    return <div style={{transitionDuration: `${EXIT_DURATION}ms`}} className={`fixed inset-0 z-[90] flex items-center justify-center p-4 backdrop-blur-sm transition-opacity ease-out motion-reduce:duration-150 ${isVisible ? 'bg-slate-950/65 opacity-100' : 'bg-slate-950/0 opacity-0'}`}>
        <section role="dialog" aria-modal="true" aria-label="Build Bright University announcement" aria-live="polite" style={{transitionDuration: `${ENTER_DURATION}ms`}} className={`max-h-[calc(100dvh-2rem)] w-full max-w-[42rem] overflow-y-auto rounded-2xl border border-white/20 bg-slate-950 p-2.5 shadow-2xl shadow-slate-950/60 transition-[opacity,transform] ease-out motion-reduce:translate-y-0 motion-reduce:scale-100 motion-reduce:duration-150 ${isVisible ? 'translate-y-0 scale-100 opacity-100' : 'translate-y-4 scale-90 opacity-0'}`}>
            <p className="mb-2 px-1.5 text-xs font-semibold tracking-wide text-sky-100">BBU ONLINE LEARNING</p>
            <img src={announcementImage} alt="Build Bright University 2026 announcement" className="h-auto w-full rounded-xl object-contain" onError={() => setPhase('hidden')}/>
        </section>
    </div>;
}

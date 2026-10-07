import React from 'react';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {useBBUBuddyState, BUDDY_STATES} from './useBBUBuddyState';

/**
 * BBU Buddy visual shell.
 *
 * A temporary, replaceable mascot shell. It renders nothing but the assistant
 * state: a status ring, a label and the current state text. The actual mascot
 * artwork will swap in later; this component is the single render target so the
 * rest of the assistant only ever talks to a stable interface.
 */
const STATE_COLORS = {
    idle: 'bg-slate-400',
    listening: 'bg-sky-500',
    transcribing: 'bg-violet-500',
    translating: 'bg-amber-500',
    thinking: 'bg-fuchsia-500',
    taking_notes: 'bg-emerald-500',
    success: 'bg-emerald-500',
    warning: 'bg-rose-500',
    sleeping: 'bg-slate-600',
};

export function BBUBuddy({state: controlledState, onStateChange, children, className = '', label}) {
    const {t} = useTranslation();
    const {state, set, transition, reset} = useBBUBuddyState({
        initialState: controlledState ?? 'idle',
        onStateChange,
    });

    void set;
    void transition;
    void reset;
    void BUDDY_STATES;

    const active = controlledState ?? state;
    const color = STATE_COLORS[active] || STATE_COLORS.idle;
    const localeKey = active === 'taking_notes' ? 'takingNotes' : active;
    const stateLabel = t(`meetingRoom.ai.states.${localeKey}`);
    const title = label || t('meetingRoom.ai.buddy');

    return (
        <div className={`bbu-buddy flex items-center gap-3 rounded-2xl border border-white/10 bg-slate-900/80 px-4 py-3 shadow-lg ${className}`}>
            <span className={`bbu-buddy__ring relative flex h-12 w-12 shrink-0 items-center justify-center rounded-full ${color}`}>
                <span className="bbu-buddy__pulse absolute inset-0 rounded-full bg-white/20 animate-ping opacity-70" aria-hidden="true"/>
                <span className="relative h-3 w-3 rounded-full bg-white" aria-hidden="true"/>
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-bold text-white">{title}</p>
                <p className="truncate text-xs text-slate-300">{stateLabel}</p>
            </div>
            {children}
        </div>
    );
}

export default BBUBuddy;
import React, {useCallback, useEffect, useMemo} from 'react';
import {MeetingSidePanel} from '../LiveKit/MeetingSidePanel';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {useBBUBuddyState, BUDDY_STATES} from './useBBUBuddyState';
import BBUBuddy from './BBUBuddy';

const REAL_ACTIONS = BUDDY_STATES.filter((state) => !['listening', 'transcribing', 'translating'].includes(state));

function stateIndex(state) {
    return REAL_ACTIONS.indexOf(state);
}

export function BBUBuddyPanel({open, onClose, initialState = 'idle', onStateChange, meeting, schoolClass, canGenerateNotes, canGenerateSummary, notes, summary, onGenerateNotes, onGenerateSummary}) {
    const {t} = useTranslation();
    const {state, set, reset} = useBBUBuddyState({initialState, onStateChange});

    const active = state;
    const localeKey = active === 'taking_notes' ? 'takingNotes' : active;
    const actions = useMemo(() => REAL_ACTIONS.map((key) => ({
        key,
        label: t(`meetingRoom.ai.states.${key === 'taking_notes' ? 'takingNotes' : key}`),
        tone: {
            idle: 'bg-slate-600',
            thinking: 'bg-fuchsia-500',
            taking_notes: 'bg-emerald-500',
            success: 'bg-emerald-500',
            warning: 'bg-rose-500',
            sleeping: 'bg-slate-600',
        }[key] || 'bg-slate-600',
    })), [t]);

    const move = useCallback((next) => {
        if (stateIndex(next) === -1) return;
        set(next);
    }, [set]);

    const resetToIdle = useCallback(() => reset(), [reset]);

    useEffect(() => {
        if (notes?.note?.status === 'generating') {
            set('taking_notes');
        } else if (summary?.summary?.status === 'generating') {
            set('thinking');
        } else if (notes?.note?.status === 'ready' || summary?.summary?.status === 'ready') {
            set('success');
        } else if (notes?.note?.status === 'failed' || summary?.summary?.status === 'failed') {
            set('warning');
        } else {
            set('idle');
        }
    }, [notes?.note?.status, summary?.summary?.status, set]);

    if (!open) return null;

    const busy = notes?.loading || summary?.loading;

    return (
        <MeetingSidePanel title={t('meetingRoom.ai.buddy')} icon="sparkles" onClose={onClose}>
            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                <BBUBuddy state={active} />

                <div className="rounded-xl border border-white/10 bg-slate-900/60 p-3">
                    <p className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{t('meetingRoom.ai.states.success')}</p>
                    <p className="text-sm text-slate-200">{t(`meetingRoom.ai.states.${localeKey}`)}</p>
                </div>

                {canGenerateNotes && (
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-400">{t('meetingRoom.ai.lessonNotes')}</p>
                            {notes?.note?.status === 'ready' && <span className="text-[10px] font-semibold uppercase tracking-wide text-emerald-300">{t('meetingRoom.ai.states.success')}</span>}
                            {notes?.note?.status === 'generating' && <span className="text-[10px] font-semibold uppercase tracking-wide text-fuchsia-300">{t('meetingRoom.ai.generatingNotes')}</span>}
                            {notes?.note?.status === 'failed' && <span className="text-[10px] font-semibold uppercase tracking-wide text-rose-300">{t('meetingRoom.ai.states.warning')}</span>}
                        </div>
                        {notes?.note?.status === 'ready' && notes.note.content ? (
                            <div className="rounded-xl border border-white/10 bg-slate-900/60 p-3">
                                <p className="whitespace-pre-wrap text-sm text-slate-200">{notes.note.content}</p>
                            </div>
                        ) : notes?.note?.status === 'failed' ? (
                            <p className="text-xs text-rose-200">{t('meetingRoom.ai.notesUnavailable')}</p>
                        ) : notes?.loading ? (
                            <p className="text-xs text-slate-300">{t('meetingRoom.ai.generatingNotes')}</p>
                        ) : (
                            <button type="button" disabled={busy} onClick={onGenerateNotes} className="w-full rounded-lg bg-sky-700 px-3 py-2 text-xs font-bold text-white transition hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600 disabled:cursor-not-allowed disabled:opacity-60">{t('meetingRoom.ai.lessonNotes')}</button>
                        )}
                    </div>
                )}

                {canGenerateSummary && (
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <p className="text-xs font-bold uppercase tracking-wide text-slate-400">{t('meetingRoom.ai.aiSummary')}</p>
                            {summary?.summary?.status === 'ready' && <span className="text-[10px] font-semibold uppercase tracking-wide text-emerald-300">{t('meetingRoom.ai.states.success')}</span>}
                            {summary?.summary?.status === 'generating' && <span className="text-[10px] font-semibold uppercase tracking-wide text-fuchsia-300">{t('meetingRoom.ai.generatingSummary')}</span>}
                            {summary?.summary?.status === 'failed' && <span className="text-[10px] font-semibold uppercase tracking-wide text-rose-300">{t('meetingRoom.ai.states.warning')}</span>}
                        </div>
                        {summary?.summary?.status === 'ready' && summary.summary.content ? (
                            <div className="rounded-xl border border-white/10 bg-slate-900/60 p-3">
                                <p className="whitespace-pre-wrap text-sm text-slate-200">{summary.summary.content}</p>
                            </div>
                        ) : summary?.summary?.status === 'failed' ? (
                            <p className="text-xs text-rose-200">{t('meetingRoom.ai.summaryUnavailable')}</p>
                        ) : summary?.loading ? (
                            <p className="text-xs text-slate-300">{t('meetingRoom.ai.generatingSummary')}</p>
                        ) : (
                            <button type="button" disabled={busy} onClick={onGenerateSummary} className="w-full rounded-lg bg-sky-700 px-3 py-2 text-xs font-bold text-white transition hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600 disabled:cursor-not-allowed disabled:opacity-60">{t('meetingRoom.ai.aiSummary')}</button>
                        )}
                    </div>
                )}

                <div className="grid grid-cols-2 gap-2">
                    {actions.map((action) => (
                        <button
                            key={action.key}
                            type="button"
                            onClick={() => move(action.key)}
                            className={`min-h-10 rounded-lg border px-3 py-2 text-xs font-bold transition focus:outline-none focus:ring-2 focus:ring-violet-400 ${
                                active === action.key
                                    ? 'border-violet-300/60 bg-violet-600 text-white'
                                    : 'border-white/10 bg-white/5 text-slate-200 hover:bg-white/10'
                            }`}
                            aria-label={action.label}
                            aria-pressed={active === action.key}
                        >
                            <span className={`inline-flex h-2 w-2 rounded-full ${action.tone}`} aria-hidden="true"/>
                            {action.label}
                        </button>
                    ))}
                </div>

                <div className="flex gap-2">
                    <button type="button" onClick={() => move('thinking')} className="min-h-10 flex-1 rounded-lg bg-sky-700 px-3 py-2 text-xs font-bold text-white hover:bg-sky-800 focus:outline-none focus:ring-2 focus:ring-sky-600">
                        {t('meetingRoom.ai.states.thinking')}
                    </button>
                    <button type="button" onClick={resetToIdle} className="min-h-10 flex-1 rounded-lg border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold text-slate-200 hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-violet-400">
                        {t('meetingRoom.ai.states.idle')}
                    </button>
                </div>
            </div>
        </MeetingSidePanel>
    );
}

export default BBUBuddyPanel;

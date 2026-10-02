import React, {useEffect, useId, useMemo, useRef, useState} from 'react';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {formatRecordingClock, recordingBadgeSeconds, recordingLive, validateCustomDuration} from './meetingRecordingState';

const PRESET_MINUTES = [5, 10, 12, 30];

/**
 * The authoritative recording state, shown to everyone and dismissible by nobody.
 *
 * A student cannot hide this, and cannot be handed a claim that a recording is not
 * running: both the fact and the countdown come from the server's own projection of
 * the recording row, anchored on the server clock that accompanied it.
 *
 * Nothing here decides when a recording stops. The badge counts down to the stored
 * deadline; the server is what acts on it.
 */
export function RecordingBadge({recording, clock}) {
    const {t} = useTranslation();
    const [now, setNow] = useState(() => performance.now());

    // Re-render against the server-anchored clock rather than accumulating a local
    // countdown, so a hidden tab, a suspended laptop or a reconnect cannot make the
    // badge drift away from what the server will actually do at the deadline.
    useEffect(() => {
        if (!recording) return undefined;
        const timer = setInterval(() => setNow(performance.now()), 1000);

        return () => clearInterval(timer);
    }, [recording]);

    if (!recording) return null;

    const live = recordingLive(recording);
    const settling = !live && (recording.status === 'stopping' || recording.status === 'processing');
    if (!live && !settling) return null;

    const seconds = live ? recordingBadgeSeconds(recording, clock, now) : null;

    return <div
        className={`meeting-recording-badge meeting-recording-badge--${settling ? 'settling' : 'live'}`}
        role="status"
        aria-live="polite"
        data-testid="meeting-recording-badge">
        <span className="meeting-recording-dot" aria-hidden="true"/>
        <span className="font-semibold">{settling ? t('meetingRoom.recording.stopping') : t('meetingRoom.recording.recording')}</span>
        {live && Number.isFinite(seconds) && <span className="font-mono tabular-nums" data-testid="meeting-recording-timer">{formatRecordingClock(seconds)}</span>}
        <span className="sr-only">{live ? t('meetingRoom.recording.notice') : t('meetingRoom.recording.settlingNotice')}</span>
    </div>;
}

/**
 * The start and stop dialogs.
 *
 * They take the whole recording API rather than a handful of props so that the
 * dialog, the button that opened it and the state it submits all read from one
 * place, and the control bar can stay a pure presentation of that API.
 */
export function MeetingRecordingDialogs({recording}) {
    if (!recording?.dialog) return null;

    return recording.dialog === 'start'
        ? <StartRecordingDialog recording={recording}/>
        : <StopRecordingDialog recording={recording}/>;
}

function StartRecordingDialog({recording}) {
    const {t} = useTranslation();
    const groupId = useId();
    const [choice, setChoice] = useState('manual');
    const [customMinutes, setCustomMinutes] = useState('');
    const bounds = useMemo(() => ({
        min: recording.minDurationMinutes ?? 1,
        max: recording.maxDurationMinutes ?? 240,
    }), [recording.minDurationMinutes, recording.maxDurationMinutes]);

    // Reopening always starts from the open-ended choice, so a cancelled dialog
    // cannot leave a stale custom value waiting behind the next open.
    useEffect(() => {
        setChoice('manual');
        setCustomMinutes('');
    }, [recording.dialogToken]);

    const custom = choice === 'custom' ? validateCustomDuration(customMinutes, bounds) : {valid: true};

    const submit = () => {
        if (choice === 'manual') return recording.start(null);
        if (choice === 'custom') {
            if (!custom.valid) return;
            return recording.start(custom.minutes);
        }

        return recording.start(Number(choice));
    };

    return <Dialog labelledBy={`${groupId}-title`}>
        <h2 id={`${groupId}-title`} className="text-base font-semibold text-white">{t('meetingRoom.recording.startTitle')}</h2>
        <p className="mt-1 text-xs text-slate-300">{t('meetingRoom.recording.startHint')}</p>

        <fieldset className="mt-4 space-y-2">
            <legend className="sr-only">{t('meetingRoom.recording.durationLegend')}</legend>
            <DurationOption name={groupId} value="manual" checked={choice === 'manual'} onChange={setChoice} label={t('meetingRoom.recording.durationManual')}/>
            {PRESET_MINUTES.map((minutes) => <DurationOption
                key={minutes}
                name={groupId}
                value={String(minutes)}
                checked={choice === String(minutes)}
                onChange={setChoice}
                label={t('meetingRoom.recording.durationMinutes', {minutes})}/>)}
            <div className="flex items-center gap-2">
                <DurationOption name={groupId} value="custom" checked={choice === 'custom'} onChange={setChoice} label={t('meetingRoom.recording.durationCustom')}/>
                <input
                    id={`${groupId}-custom`}
                    type="number"
                    inputMode="numeric"
                    min={bounds.min}
                    max={bounds.max}
                    value={customMinutes}
                    onChange={(event) => setCustomMinutes(event.target.value)}
                    disabled={choice !== 'custom'}
                    aria-label={t('meetingRoom.recording.customDurationLabel')}
                    aria-invalid={choice === 'custom' && !custom.valid}
                    className="w-24 rounded-lg border border-white/15 bg-white/10 px-2 py-1.5 text-sm text-white outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-40"/>
            </div>
        </fieldset>

        {choice === 'custom' && !custom.valid && <p role="alert" className="mt-2 text-xs text-rose-300" data-testid="meeting-recording-custom-error">{t(custom.key, custom.values)}</p>}
        <p className="mt-2 text-xs text-slate-400">{t('meetingRoom.recording.durationBounds', {min: bounds.min, max: bounds.max})}</p>

        <div className="mt-5 flex justify-end gap-2">
            <button type="button" onClick={recording.closeDialog} className="rounded-xl px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-white/10">{t('meetingRoom.recording.cancel')}</button>
            <button type="button" onClick={submit} className="rounded-xl bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700" data-testid="meeting-recording-confirm-start">{t('meetingRoom.recording.confirmStart')}</button>
        </div>
    </Dialog>;
}

function DurationOption({name, value, checked, onChange, label}) {
    return <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-100">
        <input type="radio" name={name} value={value} checked={checked} onChange={() => onChange(value)} className="h-4 w-4 accent-violet-500"/>
        {label}
    </label>;
}

function StopRecordingDialog({recording}) {
    const {t} = useTranslation();
    const cancelRef = useRef(null);

    useEffect(() => {
        cancelRef.current?.focus();
        const onKeyDown = (event) => {
            if (event.key === 'Escape') recording.closeDialog();
        };
        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [recording]);

    return <Dialog labelledBy="meeting-recording-stop-title">
        <h2 id="meeting-recording-stop-title" className="text-base font-semibold text-white">{t('meetingRoom.recording.stopTitle')}</h2>
        <p className="mt-2 text-sm text-slate-300">{t('meetingRoom.recording.stopHint')}</p>
        <div className="mt-5 flex justify-end gap-2">
            <button ref={cancelRef} type="button" onClick={recording.closeDialog} className="rounded-xl px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-white/10">{t('meetingRoom.recording.cancel')}</button>
            <button type="button" onClick={recording.stop} className="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700" data-testid="meeting-recording-confirm-stop">{t('meetingRoom.recording.confirmStop')}</button>
        </div>
    </Dialog>;
}

function Dialog({labelledBy, children}) {
    return <div className="meeting-recording-dialog fixed inset-0 z-50 flex items-center justify-center bg-slate-950/70 p-4" role="presentation">
        <div role="dialog" aria-modal="true" aria-labelledby={labelledBy} className="w-full max-w-sm rounded-2xl border border-white/10 bg-[#171a23] p-5 shadow-2xl">
            {children}
        </div>
    </div>;
}

import React, {useEffect, useRef, useState} from 'react';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {formatRecordingClock} from '../../Meetings/LiveKit/meetingRecordingState';

const bytes = (value) => {
    if (!Number.isFinite(value) || value <= 0) return null;
    if (value < 1024 * 1024) return `${Math.ceil(value / 1024)} KB`;

    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
};

// This must run directly from the Watch button's event handler. Assigning the
// source and calling play in an effect would lose Safari's user activation.
export const activateRecordingPlayer = (video, playbackUrl) => {
    if (!video || !playbackUrl) return null;

    video.src = playbackUrl;
    video.muted = false;

    return video.play();
};

/**
 * The class-channel card for one meeting recording.
 *
 * The card points at a recording, not at a file. It is created the moment the
 * capture stops and the very same message is advanced when the provider finishes,
 * so a class sees one card that changes state rather than a new card appearing
 * later. Nothing here is a permanent file link: Watch opens an authorised route
 * that the server re-checks for this viewer.
 *
 * The three-dot menu exposes one contextual action, "Remove from chat", which is
 * the existing moderation tombstone for this server-authored card. It hides the
 * card from the shared channel view, preserves the recording file and its audit
 * history, and never touches the media. Recording deletion is intentionally not
 * offered here: there is no soft-delete/restore lifecycle yet, so it must not be
 * faked as a message hide.
 */
export default function MeetingRecordingCard({recording, message, canModerate, onModerate}) {
    const {t} = useTranslation();
    const [activated, setActivated] = useState(false);
    const [mediaFailed, setMediaFailed] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const player = useRef(null);
    const menuRef = useRef(null);

    useEffect(() => {
        if (!menuOpen) return undefined;
        const onClick = (event) => {
            if (menuRef.current && !menuRef.current.contains(event.target)) {
                setMenuOpen(false);
            }
        };
        document.addEventListener('click', onClick);
        return () => document.removeEventListener('click', onClick);
    }, [menuOpen]);

    const removeChat = () => {
        setMenuOpen(false);
        if (!canModerate || !onModerate) return;
        if (!window.confirm(t('collaboration.removeChatConfirm'))) return;
        const reason = window.prompt(t('collaboration.moderationReason'), t('collaboration.moderationReason'));
        if (!reason || !reason.trim()) return;
        onModerate(message, reason.trim());
    };
    if (!recording) return null;

    const status = recording.status;
    const failed = status === 'failed';
    const ready = status === 'ready' && Boolean(recording.playback_url);
    const processing = !failed && !ready;
    const recordedBy = recording.recorded_by?.name;
    const title = recording.meeting_title || recording.meeting?.title || t('meetingRoom.recording.cardTitle');
    const recordedAt = recording.started_at || recording.created_at;
    const duration = Number.isFinite(recording.duration_seconds) && recording.duration_seconds > 0
        ? formatRecordingClock(recording.duration_seconds)
        : null;
    const isTrashed = Boolean(recording.deleted_at || recording.trashed);
    const playInline = () => {
        const video = player.current;
        if (!video || !recording.playback_url) return;

        // Keep source assignment and play() in this click handler for Safari.
        // If playback is denied, leave the native controls available for retry.
        try {
            const attempt = activateRecordingPlayer(video, recording.playback_url);
            setActivated(true);
            attempt?.catch(() => {});
        } catch {
            setActivated(true);
        }
    };

    return <section
        className="mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white"
        data-testid="meeting-recording-card"
        data-status={status}>
        <header className="flex items-start gap-3 border-b border-slate-100 px-3 py-2.5">
            <span className="mt-0.5 text-lg" aria-hidden="true">{failed ? '⚠️' : '🎥'}</span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-900">{title}</p>
                <p className="text-xs text-slate-500">{t(`meetingRoom.recording.cardStates.${status}`)}</p>
            </div>
            {canModerate ? (<div className="relative" ref={menuRef}>
                <button type="button" aria-label={t('collaboration.actions')} onClick={() => setMenuOpen(current => !current)} className="rounded-full p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-violet-500">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="19" cy="12" r="2"/></svg>
                </button>
                {menuOpen && (<div className="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-md border border-slate-200 bg-white py-1 text-sm shadow-lg">
                    <button type="button" onClick={removeChat} className="block w-full px-3 py-2 text-left text-rose-700 hover:bg-rose-50">{t('collaboration.removeChat')}</button>
                </div>)}
            </div>) : null}
        </header>

        <div className="space-y-1.5 px-3 py-2.5 text-xs text-slate-600">
            {recordedAt && <p>{t('meetingRoom.recording.cardRecordedAt', {date: new Date(recordedAt).toLocaleString()})}</p>}
            {duration && <p data-testid="meeting-recording-duration">{t('meetingRoom.recording.cardDuration', {duration})}</p>}
            {recordedBy && <p data-testid="meeting-recording-teacher">{t('meetingRoom.recording.cardRecordedBy', {name: recordedBy})}</p>}
            {bytes(recording.size_bytes) && <p>{bytes(recording.size_bytes)}</p>}
        </div>

        <footer className="px-3 pb-3">
            {isTrashed && <p role="status" className="text-xs font-medium text-slate-700">{t('collaboration.trashedRecording') || 'This recording has been moved to Trash.'}</p>}
            {processing && !isTrashed && <p role="status" className="text-xs font-medium text-amber-700">{t('meetingRoom.recording.processing')}</p>}
            {failed && !isTrashed && <p role="status" className="text-xs font-medium text-rose-700">{t('meetingRoom.recording.failed')}</p>}
            {ready && !isTrashed && <div className="relative aspect-video overflow-hidden rounded-lg bg-slate-100" data-testid="meeting-recording-preview">
                <div className={`absolute inset-0 flex items-center justify-center text-2xl transition-opacity ${activated ? 'opacity-0' : 'opacity-100'}`} aria-hidden="true" data-testid="meeting-recording-preview-placeholder">🎥</div>
                {!mediaFailed && <video
                    ref={player}
                    className={`absolute inset-0 h-full w-full object-cover transition-opacity ${activated ? 'opacity-100' : 'pointer-events-none opacity-0'}`}
                    controls={activated}
                    playsInline
                    preload="none"
                    aria-hidden={!activated}
                    data-testid="meeting-recording-player"
                    onError={() => setMediaFailed(true)}/>}
                {!activated && !mediaFailed && <button
                    type="button"
                    className="absolute inset-0 m-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-900/75 text-lg text-white shadow-sm transition hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2"
                    aria-label={t('meetingRoom.recording.watch')}
                    onClick={playInline}
                    data-testid="meeting-recording-play">
                    ▶
                </button>}
                {mediaFailed && <a
                    href={recording.playback_url}
                    className="absolute inset-0 flex items-center justify-center text-sm font-semibold text-slate-700 underline"
                    data-testid="meeting-recording-watch-fallback">
                    {t('meetingRoom.recording.watch')}
                </a>}
            </div>}
        </footer>
    </section>;
}

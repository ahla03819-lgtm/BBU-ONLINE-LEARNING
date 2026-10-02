import React, {useState} from 'react';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {formatRecordingClock} from '../../Meetings/LiveKit/meetingRecordingState';

const bytes = (value) => {
    if (!Number.isFinite(value) || value <= 0) return null;
    if (value < 1024 * 1024) return `${Math.ceil(value / 1024)} KB`;

    return `${(value / (1024 * 1024)).toFixed(1)} MB`;
};

/**
 * The class-channel card for one meeting recording.
 *
 * The card points at a recording, not at a file. It is created the moment the
 * capture stops and the very same message is advanced when the provider finishes,
 * so a class sees one card that changes state rather than a new card appearing
 * later. Nothing here is a permanent file link: Watch opens an authorised route
 * that the server re-checks for this viewer.
 */
export default function MeetingRecordingCard({recording}) {
    const {t} = useTranslation();
    const [playing, setPlaying] = useState(false);

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
        </header>

        <div className="space-y-1.5 px-3 py-2.5 text-xs text-slate-600">
            {recordedAt && <p>{t('meetingRoom.recording.cardRecordedAt', {date: new Date(recordedAt).toLocaleString()})}</p>}
            {duration && <p data-testid="meeting-recording-duration">{t('meetingRoom.recording.cardDuration', {duration})}</p>}
            {recordedBy && <p data-testid="meeting-recording-teacher">{t('meetingRoom.recording.cardRecordedBy', {name: recordedBy})}</p>}
            {bytes(recording.size_bytes) && <p>{bytes(recording.size_bytes)}</p>}
        </div>

        <footer className="px-3 pb-3">
            {processing && <p role="status" className="text-xs font-medium text-amber-700">{t('meetingRoom.recording.processing')}</p>}
            {failed && <p role="status" className="text-xs font-medium text-rose-700">{t('meetingRoom.recording.failed')}</p>}
            {ready && (playing
                ? <video
                    className="w-full rounded-lg"
                    src={recording.playback_url}
                    controls
                    autoPlay
                    preload="metadata"
                    data-testid="meeting-recording-player"
                    onError={() => setPlaying(false)}/>
                : <button
                    type="button"
                    className="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-violet-700"
                    onClick={() => setPlaying(true)}
                    data-testid="meeting-recording-watch">
                    ▶ {t('meetingRoom.recording.watch')}
                </button>)}
        </footer>
    </section>;
}

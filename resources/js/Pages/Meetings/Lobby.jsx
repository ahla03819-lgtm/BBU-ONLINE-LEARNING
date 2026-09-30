import React, {useEffect, useRef, useState} from 'react';
import {Head, Link, usePage} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import SectionCard from '../../Components/UI/SectionCard';
import UserAvatar from '../../Components/UI/UserAvatar';
import MeetingStatusBadge from '../../Components/Meetings/MeetingStatusBadge';
import useMediaPreview from '../../Hooks/Meetings/useMediaPreview';
import {echo} from '../../realtime/echo';
import {useAppSounds} from '../../Sound/AppSounds';
import {usePersistentMeeting} from '../../Providers/PersistentMeetingProvider';
import {localCameraMirrorClass} from '../../Components/Meetings/LiveKit/meetingView';
import {clearMeetingMediaIntent, meetingMediaIntentKey, readMeetingMediaIntent, writeMeetingMediaIntent} from '../../Components/Meetings/LiveKit/meetingMediaIntent';
import {useTranslation} from '../../i18n/LocaleProvider';
import {noticeKey, noticeRaw, renderNotice} from '../../i18n/notice';

export default function Lobby({schoolClass, meeting: initialMeeting, resumeSession = false}) {
    const {t} = useTranslation();
    const [meeting, setMeeting] = useState(initialMeeting);
    const [joining, setJoining] = useState(false);
    // A notice descriptor, not a translated string: server text stays raw and the
    // catalog fallbacks resolve at render time.
    const [error, setError] = useState(null);
    const [joinRequest, setJoinRequest] = useState(initialMeeting.join_request);
    const [pendingRequests, setPendingRequests] = useState([]);
    const [deciding, setDeciding] = useState(null);
    const media = useMediaPreview();
    const sounds = useAppSounds();
    const {activeMeeting, startMeeting, leaveMeeting, returnToMeeting} = usePersistentMeeting();
    const {props: pageProps} = usePage();
    const mediaIntentKey = meetingMediaIntentKey(meeting.uuid, pageProps.auth?.user?.id);
    const previousJoinRequestStatus = useRef(initialMeeting.join_request?.status ?? null);
    const knownPendingRequests = useRef(null);
    const previousMeetingStatus = useRef(meeting.status);
    const resumeAttempted = useRef(false);

    useEffect(() => {
        if (!echo) return;
        const name = `meetings.class.${schoolClass.id}`;
        const channel = echo.private(name);
        const events = ['scheduled', 'updated', 'started', 'ending', 'ended', 'cancelled'];
        const updateMeeting = ({meeting: update}) => setMeeting((current) => update.lifecycle_version > current.lifecycle_version ? {...current, ...update, can_join: update.status === 'active'} : current);
        events.forEach((event) => channel.listen(`.meeting.${event}`, updateMeeting));
        const removed = ({participant}) => {
            if (participant.reference !== meeting.participant_reference) return;
            setError(noticeKey('meetingRoom.errors.removed'));
            if (activeMeeting?.meeting.uuid === meeting.uuid) leaveMeeting();
            media.stop();
        };
        channel.listen('.meeting.participant-removed', removed);
        return () => {
            events.forEach((event) => channel.stopListening(`.meeting.${event}`, updateMeeting));
            channel.stopListening('.meeting.participant-removed', removed);
        };
    }, [activeMeeting?.meeting.uuid, leaveMeeting, schoolClass.id, meeting.participant_reference, meeting.uuid, media.stop]);

    useEffect(() => {
        const wasActive = previousMeetingStatus.current === 'active';
        if (wasActive && ['ending', 'ended', 'cancelled'].includes(meeting.status)) sounds.play('meeting-ended', meeting.uuid);
        previousMeetingStatus.current = meeting.status;
        if (['ending', 'ended', 'cancelled'].includes(meeting.status)) {
            if (activeMeeting?.meeting.uuid === meeting.uuid) leaveMeeting();
            media.stop();
        }
    }, [activeMeeting?.meeting.uuid, leaveMeeting, meeting.status, meeting.uuid, media.stop, sounds]);

    useEffect(() => {
        if (meeting.status !== 'active' || meeting.can_bypass_waiting_room) return;
        const refresh = () => fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`, {headers: {Accept: 'application/json'}}).then((response) => response.ok ? response.json() : null).then((data) => data && setJoinRequest(data.request));
        refresh();
        const interval = window.setInterval(refresh, 5000);
        return () => window.clearInterval(interval);
    }, [meeting.status, meeting.can_bypass_waiting_room, schoolClass.id, meeting.uuid]);

    useEffect(() => {
        const current = joinRequest?.status;
        if (previousJoinRequestStatus.current === 'pending' && current === 'admitted') sounds.play('admitted', `${meeting.uuid}:${joinRequest.reference}`);
        previousJoinRequestStatus.current = current || null;
    }, [joinRequest?.reference, joinRequest?.status, meeting.uuid, sounds]);

    useEffect(() => {
        if (meeting.status !== 'active' || !meeting.can_manage_join_requests) return;
        const refresh = () => fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room/requests`, {headers: {Accept: 'application/json'}}).then((response) => response.ok ? response.json() : null).then((data) => {
            if (!data) return;
            const references = data.requests.map((request) => request.reference);
            if (knownPendingRequests.current) references.filter((reference) => !knownPendingRequests.current.has(reference)).forEach((reference) => sounds.play('waiting-room-request', `${meeting.uuid}:${reference}`));
            knownPendingRequests.current = new Set(references);
            setPendingRequests(data.requests);
        });
        refresh();
        const interval = window.setInterval(refresh, 5000);
        return () => window.clearInterval(interval);
    }, [meeting.status, meeting.can_manage_join_requests, schoolClass.id, meeting.uuid, sounds]);

    const issueToken = async () => {
        if (!meeting.can_join || meeting.status !== 'active' || joining) return;
        if (activeMeeting) {
            if (activeMeeting.meeting.uuid === meeting.uuid) return returnToMeeting();
            setError(noticeKey('meetingRoom.errors.alreadyInAnother'));
            return;
        }
        setJoining(true); setError(null); media.stop();

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/token`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) {
                if ([401, 403, 404].includes(response.status)) clearMeetingMediaIntent(mediaIntentKey);
                throw new Error(data.message || data.errors?.meeting?.[0] || '');
            }
            const clock = {serverNowAt: data.server_now_at, receivedAt: performance.now()};
            const roomUrl = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/room`;
            const savedIntent = resumeSession ? readMeetingMediaIntent(mediaIntentKey) : null;
            const mediaIntent = savedIntent || {
                microphoneEnabled: media.microphoneEnabled,
                cameraEnabled: media.cameraEnabled,
                wasScreenSharing: false,
            };
            writeMeetingMediaIntent(mediaIntentKey, mediaIntent);
            const started = startMeeting({
                credentials: data,
                meeting: {...meeting, lifecycle_version: data.lifecycle_version, session_started_at: data.session_started_at},
                clock,
                schoolClass,
                participantReference: meeting.participant_reference,
                initialMedia: {camera: media.cameraEnabled, microphone: media.microphoneEnabled, cameraId: media.cameraId, microphoneId: media.microphoneId},
                mediaIntent,
                mediaIntentKey,
                roomUrl,
                lobbyUrl: `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/lobby`,
                onLeave: async () => {
                    if (!meeting.can_bypass_waiting_room && joinRequest?.status === 'admitted') {
                        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                        await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrfToken, Accept: 'application/json'}});
                    }
                },
            });
            if (!started.ok) throw new Error(started.message);
        } catch (problem) { setError(problem.message === 'Failed to fetch' ? noticeKey('meetingRoom.errors.providerUnavailable') : noticeRaw(problem.message) || noticeKey('meetingRoom.errors.joinFailed')); }
        finally { setJoining(false); }
    };
    useEffect(() => {
        if (!resumeSession || resumeAttempted.current || activeMeeting?.meeting.uuid === meeting.uuid) return;
        if (meeting.status !== 'active' || !meeting.can_join) return;
        if (!meeting.can_bypass_waiting_room && !joinRequest?.can_enter) return;

        resumeAttempted.current = true;
        issueToken();
    }, [activeMeeting?.meeting.uuid, joinRequest?.can_enter, meeting.can_bypass_waiting_room, meeting.can_join, meeting.status, meeting.uuid, resumeSession]);
    const join = async () => {
        if (meeting.can_bypass_waiting_room || (joinRequest?.status === 'admitted' && joinRequest?.can_enter)) return issueToken();
        if (!meeting.can_join || meeting.status !== 'active' || joining) return;
        setJoining(true); setError(null);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || '');
            setJoinRequest(data.request);
        } catch (problem) { setError(noticeRaw(problem.message) || noticeKey('meetingRoom.errors.requestFailed')); }
        finally { setJoining(false); }
    };
    const cancelRequest = async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
        const data = await response.json();
        if (response.ok) setJoinRequest(data.request); else setError(noticeRaw(data.message) || noticeKey('meetingRoom.errors.cancelRequestFailed'));
    };
    const decide = async (reference, decision) => {
        if (deciding) return;
        setDeciding(reference); setError(null);
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room/requests/${reference}`, {method: 'PATCH', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({decision})});
            if (!response.ok) throw new Error();
            setPendingRequests((items) => items.filter((request) => request.reference !== reference));
        } catch { setError(noticeKey('meetingRoom.errors.updateRequestFailed')); }
        finally { setDeciding(null); }
    };
    const classContext = `${schoolClass.name}${schoolClass.section ? ` · ${schoolClass.section}` : ''}${meeting.subject ? ` · ${meeting.subject.code} ${meeting.subject.name}` : ''}`;
    return <Layout>
        <Head title={`${meeting.title} · ${t('meetingRoom.lobby.title')}`}/>
        <div className="mx-auto max-w-6xl space-y-6">
            <Link href={`/school-classes/${schoolClass.id}/meetings/${meeting.uuid}`} className="inline-flex items-center gap-2 text-sm font-semibold text-indigo-700 transition hover:text-indigo-900"><Icon name="arrow-left" className="h-4 w-4"/>{t('meetingRoom.lobby.backLabel')}</Link>
            <header className="relative overflow-hidden rounded-3xl border border-indigo-100 bg-[linear-gradient(120deg,#f4f2ff,#fff_58%,#eef3ff)] px-6 py-7 shadow-[0_12px_28px_rgba(74,67,160,.08)] sm:px-8"><div className="pointer-events-none absolute -right-10 -top-10 h-44 w-44 rounded-full bg-violet-200/35 blur-3xl"/><div className="relative flex flex-wrap items-start justify-between gap-4"><div><div className="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-[.16em] text-indigo-600"><Icon name="video" className="h-4 w-4"/>{t('meetingRoom.lobby.title')}</div><h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{meeting.title}</h1><p className="mt-2 text-sm text-slate-600">{classContext}</p></div><MeetingStatusBadge status={meeting.status}/></div></header>
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1.25fr)_minmax(22rem,.75fr)]">
                <section className="relative overflow-hidden rounded-3xl border border-slate-800 bg-[#151729] p-3 shadow-[0_20px_40px_rgba(24,24,52,.2)]"><div className="absolute inset-x-0 top-0 h-20 bg-[radial-gradient(circle_at_72%_0%,rgba(129,111,255,.4),transparent_55%)]"/><div className="relative flex items-center justify-between px-2 pb-3 text-xs font-semibold text-slate-300"><span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-emerald-400"/>{t('meetingRoom.lobby.deviceCheck')}</span><span>{t('meetingRoom.lobby.previewOnly')}</span></div><div className="relative aspect-video overflow-hidden rounded-2xl bg-gradient-to-br from-[#292c54] to-[#0b0c19]"><video ref={media.videoRef} autoPlay muted playsInline className={`h-full w-full object-cover ${localCameraMirrorClass}`} aria-label={t('meetingRoom.lobby.cameraPreviewLabel')}/>{!media.cameraEnabled && <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 text-slate-300"><span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 text-violet-200"><Icon name="video-off" className="h-7 w-7"/></span><p className="text-sm font-medium">{t('meetingRoom.lobby.cameraIsOff')}</p><button className="rounded-xl bg-white/10 px-4 py-2 text-xs font-semibold text-white transition hover:bg-white/20" onClick={media.toggleCamera}>{t('meetingRoom.lobby.turnOnCamera')}</button></div>}</div><p className="relative px-2 pt-3 text-xs leading-5 text-slate-400">{t('meetingRoom.lobby.previewHint')}</p></section>
                <SectionCard className="p-6" title={t(`meetingRoom.lobby.${joinRequest?.status === 'pending' ? 'waitingRoom' : 'readyToJoin'}`)}
                description={t(`meetingRoom.lobby.${joinRequest?.status === 'pending' ? 'waitingDescription' : 'readyDescription'}`)}>{media.error && <p className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800" role="alert">{renderNotice(media.error, t)}</p>}{error && <p className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{renderNotice(error, t)}</p>}{joinRequest?.status === 'pending' ? <WaitingRoom request={joinRequest} onCancel={cancelRequest}/> : <><div className="mt-5 grid grid-cols-2 gap-3"><DeviceToggle enabled={media.cameraEnabled} icon={media.cameraEnabled ? 'video' : 'video-off'} label={t(media.cameraEnabled ? 'meetingRoom.lobby.cameraOn' : 'meetingRoom.lobby.cameraOff')} onClick={media.toggleCamera}/><DeviceToggle enabled={media.microphoneEnabled} icon={media.microphoneEnabled ? 'mic' : 'mic-off'} label={t(media.microphoneEnabled ? 'meetingRoom.lobby.micOn' : 'meetingRoom.lobby.micOff')} onClick={media.toggleMicrophone}/></div><DeviceSelect label={t('meetingRoom.lobby.cameraLabel')} icon="video" value={media.cameraId} onChange={(event) => media.chooseCamera(event.target.value)} options={media.devices.cameras} optionLabel={media.labels.camera} defaultLabel={t('meetingRoom.lobby.defaultCamera')}/><DeviceSelect label={t('meetingRoom.lobby.microphoneLabel')} icon="mic" value={media.microphoneId} onChange={(event) => media.chooseMicrophone(event.target.value)} options={media.devices.microphones} optionLabel={media.labels.microphone} defaultLabel={t('meetingRoom.lobby.defaultMicrophone')}/><div className="mt-4 rounded-2xl border border-slate-200 bg-slate-50/70 p-3"><div className="flex items-center justify-between gap-3"><div><p className="text-sm font-semibold text-slate-800">{t('meetingRoom.lobby.microphoneTest')}</p><p className="mt-0.5 text-xs text-slate-500">{t('meetingRoom.lobby.microphoneTestHint')}</p></div><button type="button" className="btn-secondary shrink-0 px-3 py-2 text-xs" onClick={media.testMicrophone}>{t(media.testingMicrophone ? 'meetingRoom.lobby.stopTest' : 'meetingRoom.lobby.testMic')}</button></div><div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-200"><div className="h-full rounded-full bg-emerald-500 transition-[width] duration-100" style={{width: `${media.microphoneLevel}%`}}/></div></div><DeviceSelect label={t('meetingRoom.lobby.speakerLabel')} icon="volume" value={media.speakerId} onChange={(event) => media.setSpeakerId(event.target.value)} options={media.devices.speakers} optionLabel={media.labels.speaker} defaultLabel={t('meetingRoom.lobby.defaultSpeaker')} disabled={!media.speakerSelectionSupported}/><div className="mt-2 flex items-center justify-between gap-3"><p className="text-xs text-slate-500">{t(media.speakerSelectionSupported ? 'meetingRoom.lobby.speakerHint' : 'meetingRoom.lobby.speakerDefaultHint')}</p><button type="button" className="btn-secondary shrink-0 px-3 py-2 text-xs" onClick={media.testSpeaker}>{t(media.testingSpeaker ? 'meetingRoom.lobby.playing' : 'meetingRoom.lobby.testSpeaker')}</button><audio ref={media.speakerRef} preload="none" className="hidden"/></div><button className="btn mt-6 w-full justify-center py-3" disabled={!meeting.can_join || meeting.status !== 'active' || joining} onClick={join}><Icon name="video" className="h-4 w-4"/>{joining
                        ? t('meetingRoom.lobby.joining')
                        : joinRequest?.status === 'admitted' && joinRequest?.can_enter
                            ? t('meetingRoom.lobby.enterLiveClass')
                            : joinRequest?.status === 'denied'
                                ? t('meetingRoom.lobby.requestToJoinAgain')
                                : meeting.status === 'active'
                                    ? (meeting.can_bypass_waiting_room ? t('meetingRoom.lobby.joinMeeting') : t('meetingRoom.lobby.requestToJoin'))
                                    : t('meetingRoom.lobby.waitingForActive')}</button></>}</SectionCard>
            </div>
            {meeting.can_manage_join_requests && <HostRequests requests={pendingRequests} deciding={deciding} onDecide={decide}/>}
        </div>
    </Layout>;
}

function DeviceToggle({enabled, icon, label, onClick}) {
    return <button className={`flex items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-indigo-500 ${enabled ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`} onClick={onClick} aria-pressed={enabled}><Icon name={icon} className="h-4 w-4"/>{label}</button>;
}

function DeviceSelect({label, icon, value, onChange, options, optionLabel, defaultLabel, disabled = false}) {
    return <label className="mt-4 block text-sm font-semibold text-slate-700"><span className="inline-flex items-center gap-2"><Icon name={icon} className="h-4 w-4 text-indigo-600"/>{label}</span><select className="input mt-2" value={value} onChange={onChange} disabled={disabled}><option value="">{defaultLabel}</option>{options.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{optionLabel(device, index)}</option>)}</select></label>;
}

function WaitingRoom({request, onCancel}) {
    const {t} = useTranslation();

    return <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-5 text-center"><span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-indigo-600 shadow-sm"><Icon name="clock" className="h-7 w-7 animate-pulse"/></span><p className="mt-4 font-semibold text-slate-900">{t('meetingRoom.waitingRoom.hostApprovalPending')}</p><p className="mt-2 text-sm leading-6 text-slate-600">{t('meetingRoom.waitingRoom.hostApprovalHint')}</p><button className="btn-secondary mt-5" onClick={onCancel}>{t('meetingRoom.waitingRoom.cancelRequest')}</button></div>;
}
function HostRequests({requests, deciding, onDecide}) {
    const {t} = useTranslation();

    return <SectionCard className="p-6" title={`${t('meetingRoom.waitingRoom.title')}${requests.length ? ` · ${requests.length}` : ''}`} description={t('meetingRoom.waitingRoom.description')}>{requests.length === 0 ? <p className="mt-4 text-sm text-slate-500">{t('meetingRoom.waitingRoom.empty')}</p> : <div className="mt-4 grid gap-3 md:grid-cols-2">{requests.map((request) => <div className="flex items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/45 p-4" key={request.reference}><div className="flex min-w-0 items-center gap-3"><UserAvatar name={request.display_name} avatarUrl={request.avatar_url} size="md" alt=""/><div className="min-w-0"><p className="truncate font-semibold text-slate-800">{request.display_name}</p><p className="mt-1 text-xs text-slate-500">{t('common.requested')} {new Date(request.requested_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</p></div></div><div className="flex gap-2"><button className="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" disabled={deciding !== null} onClick={() => onDecide(request.reference, 'admitted')}>{t('meetingRoom.waitingRoom.admit')}</button><button className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 disabled:opacity-50" disabled={deciding !== null} onClick={() => onDecide(request.reference, 'denied')}>{t('meetingRoom.waitingRoom.deny')}</button></div></div>)}</div>}</SectionCard>;
}

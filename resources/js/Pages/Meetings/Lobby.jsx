import React, {lazy, Suspense, useEffect, useRef, useState} from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import Icon from '../../Components/UI/Icon';
import SectionCard from '../../Components/UI/SectionCard';
import UserAvatar from '../../Components/UI/UserAvatar';
import MeetingStatusBadge from '../../Components/Meetings/MeetingStatusBadge';
import useMediaPreview from '../../Hooks/Meetings/useMediaPreview';
import {echo} from '../../realtime/echo';
import {useAppSounds} from '../../Sound/AppSounds';

const MeetingRoomExperience = lazy(() => import('../../Components/Meetings/LiveKit/MeetingRoomExperience'));

export default function Lobby({schoolClass, meeting: initialMeeting}) {
    const [meeting, setMeeting] = useState(initialMeeting);
    const [credentials, setCredentials] = useState(null);
    const [joining, setJoining] = useState(false);
    const [error, setError] = useState('');
    const [joinRequest, setJoinRequest] = useState(initialMeeting.join_request);
    const [pendingRequests, setPendingRequests] = useState([]);
    const [deciding, setDeciding] = useState(null);
    const media = useMediaPreview();
    const sounds = useAppSounds();
    const previousJoinRequestStatus = useRef(initialMeeting.join_request?.status ?? null);
    const knownPendingRequests = useRef(null);
    const previousMeetingStatus = useRef(meeting.status);

    useEffect(() => {
        if (!echo) return;
        const name = `meetings.class.${schoolClass.id}`;
        const channel = echo.private(name);
        ['scheduled', 'updated', 'started', 'ending', 'ended', 'cancelled'].forEach((event) => channel.listen(`.meeting.${event}`, ({meeting: update}) => setMeeting((current) => update.lifecycle_version > current.lifecycle_version ? {...current, ...update, can_join: update.status === 'active'} : current)));
        channel.listen('.meeting.participant-removed', ({participant}) => { if (participant.reference === meeting.participant_reference) { setError('You were removed from this meeting.'); setCredentials(null); media.stop(); } });
        return () => echo.leave(name);
    }, [schoolClass.id, meeting.participant_reference]);

    useEffect(() => {
        const wasActive = previousMeetingStatus.current === 'active';
        if (wasActive && ['ending', 'ended', 'cancelled'].includes(meeting.status)) sounds.play('meeting-ended', meeting.uuid);
        previousMeetingStatus.current = meeting.status;
        if (['ending', 'ended', 'cancelled'].includes(meeting.status)) { setCredentials(null); media.stop(); }
    }, [meeting.status, meeting.uuid, sounds]);

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
        setJoining(true); setError(''); media.stop();
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/token`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || data.errors?.meeting?.[0] || 'Unable to join this meeting.');
            setCredentials(data); window.history.replaceState({}, '', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/room`);
        } catch (problem) { setError(problem.message === 'Failed to fetch' ? 'The meeting provider is unavailable.' : problem.message); }
        finally { setJoining(false); }
    };
    const join = async () => {
        if (meeting.can_bypass_waiting_room || (joinRequest?.status === 'admitted' && joinRequest?.can_enter)) return issueToken();
        if (!meeting.can_join || meeting.status !== 'active' || joining) return;
        setJoining(true); setError('');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Unable to request entry to this meeting.');
            setJoinRequest(data.request);
        } catch (problem) { setError(problem.message || 'Unable to request entry to this meeting.'); }
        finally { setJoining(false); }
    };
    const cancelRequest = async () => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
        const data = await response.json();
        if (response.ok) setJoinRequest(data.request); else setError(data.message || 'Unable to cancel the join request.');
    };
    const decide = async (reference, decision) => {
        if (deciding) return;
        setDeciding(reference); setError('');
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
        try {
            const response = await fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room/requests/${reference}`, {method: 'PATCH', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({decision})});
            if (!response.ok) throw new Error();
            setPendingRequests((items) => items.filter((request) => request.reference !== reference));
        } catch { setError('Unable to update this join request. Please try again.'); }
        finally { setDeciding(null); }
    };
    const leave = () => {
        if (!meeting.can_bypass_waiting_room && joinRequest?.status === 'admitted') {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            fetch(`/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/waiting-room`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}})
                .then((response) => response.ok ? response.json() : null)
                .then((data) => data?.request && setJoinRequest(data.request));
        }
        setCredentials(null); media.stop(); window.history.replaceState({}, '', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/lobby`);
    };

    if (credentials) return <Layout><Head title={meeting.title}/><Suspense fallback={<div className="edway-card flex min-h-64 items-center justify-center text-slate-600"><Icon name="loader" className="mr-3 h-5 w-5 animate-spin text-indigo-600"/>Loading meeting…</div>}><MeetingRoomExperience credentials={credentials} meeting={meeting} schoolClass={schoolClass} initialMedia={{camera: media.cameraEnabled, microphone: media.microphoneEnabled}} onLeave={leave}/></Suspense></Layout>;

    const classContext = `${schoolClass.name}${schoolClass.section ? ` · ${schoolClass.section}` : ''}${meeting.subject ? ` · ${meeting.subject.code} ${meeting.subject.name}` : ''}`;
    return <Layout>
        <Head title={`${meeting.title} lobby`}/>
        <div className="mx-auto max-w-6xl space-y-6">
            <Link href={`/school-classes/${schoolClass.id}/meetings/${meeting.uuid}`} className="inline-flex items-center gap-2 text-sm font-semibold text-indigo-700 transition hover:text-indigo-900"><Icon name="arrow-left" className="h-4 w-4"/>Meeting details</Link>
            <header className="relative overflow-hidden rounded-3xl border border-indigo-100 bg-[linear-gradient(120deg,#f4f2ff,#fff_58%,#eef3ff)] px-6 py-7 shadow-[0_12px_28px_rgba(74,67,160,.08)] sm:px-8"><div className="pointer-events-none absolute -right-10 -top-10 h-44 w-44 rounded-full bg-violet-200/35 blur-3xl"/><div className="relative flex flex-wrap items-start justify-between gap-4"><div><div className="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-[.16em] text-indigo-600"><Icon name="video" className="h-4 w-4"/>Live class lobby</div><h1 className="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">{meeting.title}</h1><p className="mt-2 text-sm text-slate-600">{classContext}</p></div><MeetingStatusBadge status={meeting.status}/></div></header>
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(20rem,.8fr)]">
                <section className="relative overflow-hidden rounded-3xl border border-slate-800 bg-[#151729] p-3 shadow-[0_20px_40px_rgba(24,24,52,.2)]"><div className="absolute inset-x-0 top-0 h-20 bg-[radial-gradient(circle_at_72%_0%,rgba(129,111,255,.4),transparent_55%)]"/><div className="relative flex items-center justify-between px-2 pb-3 text-xs font-semibold text-slate-300"><span className="inline-flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-emerald-400"/>Device check</span><span>Preview only</span></div><div className="relative aspect-video overflow-hidden rounded-2xl bg-gradient-to-br from-[#292c54] to-[#0b0c19]"><video ref={media.videoRef} autoPlay muted playsInline className="h-full w-full object-cover" aria-label="Local camera preview"/>{!media.cameraEnabled && <div className="absolute inset-0 flex flex-col items-center justify-center gap-3 text-slate-300"><span className="flex h-16 w-16 items-center justify-center rounded-2xl bg-white/10 text-violet-200"><Icon name="video-off" className="h-7 w-7"/></span><p className="text-sm font-medium">Camera is off</p></div>}</div></section>
                <SectionCard className="p-6" title={joinRequest?.status === 'pending' ? 'Waiting room' : 'Ready to join?'} description={joinRequest?.status === 'pending' ? 'Your host will review your request before entry is allowed.' : 'Choose your camera and microphone before entering the room.'}>{media.error && <p className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">{media.error}</p>}{error && <p className="mt-5 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700">{error}</p>}{joinRequest?.status === 'pending' ? <WaitingRoom request={joinRequest} onCancel={cancelRequest}/> : <><div className="mt-5 flex gap-3"><button className={`flex flex-1 items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-semibold transition ${media.cameraEnabled ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600'}`} onClick={media.toggleCamera} aria-pressed={media.cameraEnabled}><Icon name={media.cameraEnabled ? 'video' : 'video-off'} className="h-4 w-4"/>{media.cameraEnabled ? 'Camera on' : 'Camera off'}</button><button className={`flex flex-1 items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-semibold transition ${media.microphoneEnabled ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600'}`} onClick={media.toggleMicrophone} aria-pressed={media.microphoneEnabled}><Icon name={media.microphoneEnabled ? 'mic' : 'mic-off'} className="h-4 w-4"/>{media.microphoneEnabled ? 'Mic on' : 'Mic off'}</button></div><label className="mt-5 block text-sm font-semibold text-slate-700">Camera<select className="input mt-2" value={media.cameraId} onChange={(event) => media.setCameraId(event.target.value)}><option value="">Default camera</option>{media.devices.cameras.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{device.label || `Camera ${index + 1}`}</option>)}</select></label><label className="mt-4 block text-sm font-semibold text-slate-700">Microphone<select className="input mt-2" value={media.microphoneId} onChange={(event) => media.setMicrophoneId(event.target.value)}><option value="">Default microphone</option>{media.devices.microphones.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{device.label || `Microphone ${index + 1}`}</option>)}</select></label><button className="btn mt-6 w-full justify-center py-3" disabled={!meeting.can_join || meeting.status !== 'active' || joining} onClick={join}><Icon name="video" className="h-4 w-4"/>{joining ? 'Joining…' : joinRequest?.status === 'admitted' && joinRequest?.can_enter ? 'Enter live class' : joinRequest?.status === 'denied' ? 'Request to join again' : meeting.status === 'active' ? (meeting.can_bypass_waiting_room ? 'Join meeting' : 'Request to join') : 'Waiting for meeting to become active'}</button></>}</SectionCard>
            </div>
            {meeting.can_manage_join_requests && <HostRequests requests={pendingRequests} deciding={deciding} onDecide={decide}/>}
        </div>
    </Layout>;
}

function WaitingRoom({request, onCancel}) { return <div className="mt-5 rounded-2xl border border-indigo-100 bg-indigo-50/70 p-5 text-center"><span className="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-indigo-600 shadow-sm"><Icon name="clock" className="h-7 w-7 animate-pulse"/></span><p className="mt-4 font-semibold text-slate-900">Waiting for host approval</p><p className="mt-2 text-sm leading-6 text-slate-600">Your request is pending. This page checks for a decision automatically.</p><button className="btn-secondary mt-5" onClick={onCancel}>Cancel request</button></div>; }
function HostRequests({requests, deciding, onDecide}) { return <SectionCard className="p-6" title={`Waiting room${requests.length ? ` · ${requests.length}` : ''}`} description="Pending entry requests from authorized meeting participants.">{requests.length === 0 ? <p className="mt-4 text-sm text-slate-500">No participants are waiting for approval.</p> : <div className="mt-4 grid gap-3 md:grid-cols-2">{requests.map((request) => <div className="flex items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/45 p-4" key={request.reference}><div className="flex min-w-0 items-center gap-3"><UserAvatar name={request.display_name} avatarUrl={request.avatar_url} size="md" alt=""/><div className="min-w-0"><p className="truncate font-semibold text-slate-800">{request.display_name}</p><p className="mt-1 text-xs text-slate-500">Requested {new Date(request.requested_at).toLocaleTimeString([], {hour: 'numeric', minute: '2-digit'})}</p></div></div><div className="flex gap-2"><button className="rounded-xl bg-indigo-600 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" disabled={deciding !== null} onClick={() => onDecide(request.reference, 'admitted')}>Admit</button><button className="rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 disabled:opacity-50" disabled={deciding !== null} onClick={() => onDecide(request.reference, 'denied')}>Deny</button></div></div>)}</div>}</SectionCard>; }

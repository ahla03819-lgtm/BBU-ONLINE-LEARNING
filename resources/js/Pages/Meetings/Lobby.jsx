import React, {lazy, Suspense, useEffect, useState} from 'react';
import {Head, Link} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import useMediaPreview from '../../Hooks/Meetings/useMediaPreview';
import {echo} from '../../realtime/echo';

const MeetingRoomExperience = lazy(() => import('../../Components/Meetings/LiveKit/MeetingRoomExperience'));

export default function Lobby({schoolClass, meeting: initialMeeting}) {
    const [meeting, setMeeting] = useState(initialMeeting);
    const [credentials, setCredentials] = useState(null);
    const [joining, setJoining] = useState(false);
    const [error, setError] = useState('');
    const media = useMediaPreview();
    useEffect(() => {
        if (!echo) return;
        const name = `meetings.class.${schoolClass.id}`;
        const channel = echo.private(name);
        ['scheduled','updated','started','ending','ended','cancelled'].forEach((event) => channel.listen(`.meeting.${event}`, ({meeting: update}) => setMeeting((current) => update.lifecycle_version > current.lifecycle_version ? {...current, ...update, can_join: update.status === 'active'} : current)));
        channel.listen('.meeting.participant-removed', ({participant}) => { if (participant.reference === meeting.participant_reference) { setError('You were removed from this meeting.'); setCredentials(null); media.stop(); } });
        return () => echo.leave(name);
    }, [schoolClass.id, meeting.participant_reference]);
    useEffect(() => { if (['ending','ended','cancelled'].includes(meeting.status)) { setCredentials(null); media.stop(); } }, [meeting.status]);
    const join = async () => {
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
    const leave = () => { setCredentials(null); media.stop(); window.history.replaceState({}, '', `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/lobby`); };
    if (credentials) return <Layout><Head title={meeting.title}/><Suspense fallback={<p>Loading meeting…</p>}><MeetingRoomExperience credentials={credentials} meeting={meeting} schoolClass={schoolClass} initialMedia={{camera: media.cameraEnabled, microphone: media.microphoneEnabled}} onLeave={leave}/></Suspense></Layout>;
    return <Layout><Head title={`${meeting.title} lobby`}/><div className="mx-auto max-w-5xl"><Link href={`/school-classes/${schoolClass.id}/meetings/${meeting.uuid}`} className="text-indigo-600">← Meeting details</Link><div className="mt-5 grid gap-6 lg:grid-cols-2"><section className="overflow-hidden rounded-xl bg-slate-950 text-white"><video ref={media.videoRef} autoPlay muted playsInline className="aspect-video w-full object-cover" aria-label="Local camera preview"/>{!media.cameraEnabled && <p className="p-8 text-center">Camera is off</p>}</section><section className="card"><h1 className="text-2xl font-bold">{meeting.title}</h1><p className="mt-1 text-slate-600">{schoolClass.name}{schoolClass.section ? ` · ${schoolClass.section}` : ''}{meeting.subject ? ` · ${meeting.subject.code} ${meeting.subject.name}` : ''}</p><p className="mt-3" aria-live="polite">Meeting status: <strong>{meeting.status}</strong></p>{media.error && <p className="mt-3 rounded bg-amber-50 p-3 text-amber-800">{media.error}</p>}{error && <p className="mt-3 rounded bg-red-50 p-3 text-red-700">{error}</p>}<div className="mt-5 flex gap-3"><button className="btn" onClick={media.toggleCamera} aria-pressed={media.cameraEnabled}>{media.cameraEnabled ? 'Turn camera off' : 'Enable camera'}</button><button className="btn" onClick={media.toggleMicrophone} aria-pressed={media.microphoneEnabled}>{media.microphoneEnabled ? 'Mute microphone' : 'Enable microphone'}</button></div><label className="mt-4 block text-sm">Camera<select className="input mt-1" value={media.cameraId} onChange={(event) => media.setCameraId(event.target.value)}><option value="">Default camera</option>{media.devices.cameras.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{device.label || `Camera ${index + 1}`}</option>)}</select></label><label className="mt-3 block text-sm">Microphone<select className="input mt-1" value={media.microphoneId} onChange={(event) => media.setMicrophoneId(event.target.value)}><option value="">Default microphone</option>{media.devices.microphones.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{device.label || `Microphone ${index + 1}`}</option>)}</select></label><button className="btn mt-6 w-full" disabled={!meeting.can_join || meeting.status !== 'active' || joining} onClick={join}>{joining ? 'Joining…' : meeting.status === 'active' ? 'Join meeting' : 'Waiting for meeting to become active'}</button></section></div></div></Layout>;
}

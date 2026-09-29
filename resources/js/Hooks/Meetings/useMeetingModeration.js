import {useEffect, useRef, useState} from 'react';
import {useAppSounds} from '../../Sound/AppSounds';
import {useTranslation} from '../../i18n/LocaleProvider';

// These are the existing policy-protected HTTP actions, never LiveKit data commands.
export default function useMeetingModeration({meeting, schoolClass, connected, participantIdentities}) {
    const {t} = useTranslation();
    const [records, setRecords] = useState([]);
    const [requests, setRequests] = useState([]);
    const [waitingLoaded, setWaitingLoaded] = useState(false);
    const [rosterError, setRosterError] = useState('');
    const [waitingError, setWaitingError] = useState('');
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(null);
    const [revision, setRevision] = useState(0);
    const busyRef = useRef(null);
    const knownRequests = useRef(null);
    const activePoll = useRef(null);
    const mounted = useRef(true);
    const sounds = useAppSounds();
    const base = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}`;
    const available = connected && meeting.status === 'active';
    useEffect(() => { mounted.current = true; return () => { mounted.current = false; }; }, []);

    useEffect(() => {
        if (!available) { setRecords([]); setRequests([]); setWaitingLoaded(false); return; }
        let disposed = false;
        let timer;
        const controller = new AbortController();
        activePoll.current = controller;
        const load = async (suffix, accept, fail) => {
            try {
                const response = await fetch(`${base}/${suffix}`, {headers: {Accept: 'application/json'}, signal: controller.signal});
                if (!response.ok) throw new Error();
                const data = await response.json();
                if (!disposed && !controller.signal.aborted) accept(data);
            } catch {
                if (!disposed && !controller.signal.aborted) fail();
            }
        };
        const refresh = async () => {
            await Promise.allSettled([
                load('participants', (data) => { setRecords(data.participants); setRosterError(''); }, () => { setRecords([]); setRosterError(t('meetingRoom.controlCenter.moderationFailed')); }),
                meeting.can_manage_join_requests ? load('waiting-room/requests', (data) => {
                    const references = new Set(data.requests.map((request) => request.reference));
                    if (knownRequests.current) references.forEach((reference) => {
                        if (!knownRequests.current.has(reference)) sounds.play('waiting-room-request', `${meeting.uuid}:${reference}`);
                    });
                    knownRequests.current = references;
                    setRequests(data.requests); setWaitingLoaded(true); setWaitingError('');
                }, () => { setRequests([]); setWaitingLoaded(false); setWaitingError(t('meetingRoom.waitingRoom.moderationFailed')); }) : Promise.resolve(),
            ]);
            if (!disposed && !controller.signal.aborted) timer = window.setTimeout(refresh, 5000);
        };
        if (!meeting.can_manage_join_requests) { setRequests([]); setWaitingLoaded(false); }
        refresh();
        return () => { disposed = true; controller.abort(); window.clearTimeout(timer); };
    }, [available, base, meeting.uuid, meeting.can_manage_join_requests, participantIdentities, revision, sounds, t]);

    const perform = async (key, url, method, body, success) => {
        if (!available || busyRef.current) return;
        busyRef.current = key; setBusy(key); setMessage('');
        // Prevent an older poll from putting a decided request back into the UI.
        activePoll.current?.abort();
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(url, {method, headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'}, ...(body ? {body: JSON.stringify(body)} : {})});
            if (!response.ok) throw new Error();
            if (mounted.current) setMessage(success);
        } catch {
            if (mounted.current) setMessage(key === 'end' ? t('meetingRoom.panels.endFailed') : key.startsWith('remove:') ? t('meetingRoom.panels.removeFailed') : t('meetingRoom.errors.updateRequestFailed'));
        } finally {
            busyRef.current = null;
            if (mounted.current) { setBusy(null); setRevision((value) => value + 1); }
        }
    };
    const remove = (record) => {
        if (!meeting.can_manage_participants || !record?.can_remove || busyRef.current || !available) return;
        if (!window.confirm(`Remove ${record.display_name || 'this participant'} from this meeting? They will not be able to rejoin.`)) return;
        return perform(`remove:${record.reference}`, `${base}/participants/${record.reference}`, 'DELETE', null, 'Participant removed.');
    };
    const decide = (request, decision) => {
        if (!meeting.can_manage_join_requests || !['admitted', 'denied'].includes(decision) || busyRef.current || !available) return;
        if (decision === 'denied' && !window.confirm(`Reject the join request from ${request.display_name}?`)) return;
        return perform(`waiting:${request.reference}`, `${base}/waiting-room/requests/${request.reference}`, 'PATCH', {decision}, decision === 'admitted' ? 'Participant admitted.' : 'Join request rejected.');
    };
    const end = () => {
        if (!meeting.can_end || busyRef.current || !available || !window.confirm('End this active meeting for everyone?')) return;
        return perform('end', `/school-classes/${schoolClass.id}/meetings/${meeting.uuid}/end`, 'POST', null, 'End meeting request sent.');
    };
    return {records, requests, waitingLoaded, rosterError, waitingError, message, busy, available, remove, decide, end};
}

import {useEffect, useRef, useState} from 'react';
import {useAppSounds} from '../../Sound/AppSounds';
import {noticeKey} from '../../i18n/notice';
import {canStartMuteParticipant, muteResultTranslationKey, requestParticipantMute} from '../../Components/Meetings/LiveKit/participantMicrophoneModeration';
import {echo} from '../../realtime/echo';

// These are the existing policy-protected HTTP actions, never LiveKit data commands.
export default function useMeetingModeration({meeting, schoolClass, connected, participantIdentities, onMeetingEnded}) {
    const [records, setRecords] = useState([]);
    const [requests, setRequests] = useState([]);
    const [waitingLoaded, setWaitingLoaded] = useState(false);
    const [screenShareRequests, setScreenShareRequests] = useState([]);
    // These hold notice descriptors, not translated text, so a locale change
    // updates a status line that is already on screen.
    const [rosterError, setRosterError] = useState(null);
    const [waitingError, setWaitingError] = useState(null);
    const [screenShareError, setScreenShareError] = useState(null);
    const [message, setMessage] = useState(null);
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
        if (!available || !echo) return;
        const channel = echo.private(`meetings.class.${schoolClass.id}`);
        const changed = ({meeting_uuid: meetingUuid}) => { if (meetingUuid === meeting.uuid) setRevision((value) => value + 1); };
        channel.listen('.meeting.screen-share-request-changed', changed);
        return () => channel.stopListening('.meeting.screen-share-request-changed', changed);
    }, [available, meeting.uuid, schoolClass.id]);

    useEffect(() => {
        if (!available) { setRecords([]); setRequests([]); setScreenShareRequests([]); setWaitingLoaded(false); return; }
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
                load('participants', (data) => { setRecords(data.participants); setRosterError(null); }, () => { setRecords([]); setRosterError(noticeKey('meetingRoom.controlCenter.moderationFailed')); }),
                meeting.can_manage_join_requests ? load('waiting-room/requests', (data) => {
                    const references = new Set(data.requests.map((request) => request.reference));
                    if (knownRequests.current) references.forEach((reference) => {
                        if (!knownRequests.current.has(reference)) sounds.play('waiting-room-request', `${meeting.uuid}:${reference}`);
                    });
                    knownRequests.current = references;
                    setRequests(data.requests); setWaitingLoaded(true); setWaitingError(null);
                }, () => { setRequests([]); setWaitingLoaded(false); setWaitingError(noticeKey('meetingRoom.waitingRoom.moderationFailed')); }) : Promise.resolve(),
                meeting.can_manage_screen_share_requests ? load('screen-share-requests', (data) => {
                    setScreenShareRequests(data.requests || []); setScreenShareError(null);
                }, () => { setScreenShareRequests([]); setScreenShareError(noticeKey('meetingRoom.screenShare.loadFailed')); }) : Promise.resolve(),
            ]);
            if (!disposed && !controller.signal.aborted) timer = window.setTimeout(refresh, 5000);
        };
        if (!meeting.can_manage_join_requests) { setRequests([]); setWaitingLoaded(false); }
        refresh();
        return () => { disposed = true; controller.abort(); window.clearTimeout(timer); };
    }, [available, base, meeting.uuid, meeting.can_manage_join_requests, meeting.can_manage_screen_share_requests, participantIdentities, revision, sounds]);

    const perform = async (key, url, method, body, successNotice) => {
        if (!available || busyRef.current) return;
        busyRef.current = key; setBusy(key); setMessage(null);
        // Prevent an older poll from putting a decided request back into the UI.
        activePoll.current?.abort();
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(url, {method, headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'}, ...(body ? {body: JSON.stringify(body)} : {})});
            if (!response.ok) throw new Error();
            if (mounted.current) setMessage(successNotice);
        } catch {
            if (mounted.current) setMessage(noticeKey(key === 'end' ? 'meetingRoom.panels.endFailed' : key.startsWith('remove:') ? 'meetingRoom.panels.removeFailed' : 'meetingRoom.errors.updateRequestFailed'));
        } finally {
            busyRef.current = null;
            if (mounted.current) { setBusy(null); setRevision((value) => value + 1); }
        }
    };
    const remove = (record) => {
        if (!meeting.can_manage_participants || !record?.can_remove || busyRef.current || !available) return;
        if (!window.confirm(`Remove ${record.display_name || 'this participant'} from this meeting? They will not be able to rejoin.`)) return;
        return perform(`remove:${record.reference}`, `${base}/participants/${record.reference}`, 'DELETE', null, noticeKey('meetingRoom.panels.participantRemoved'));
    };
    const mute = async (record) => {
        if (!canStartMuteParticipant({available, canManageParticipants: meeting.can_manage_participants, record, busy: busyRef.current})) return;
        const key = `mute:${record.reference}`;
        busyRef.current = key; setBusy(key); setMessage(null);
        activePoll.current?.abort();
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await requestParticipantMute({base, reference: record.reference, csrf});
            const data = await response.json().catch(() => ({}));
            if (mounted.current) setMessage(noticeKey(muteResultTranslationKey(data.result, response.ok)));
        } catch {
            if (mounted.current) setMessage(noticeKey('meetingRoom.panels.muteFailed'));
        } finally {
            busyRef.current = null;
            if (mounted.current) { setBusy(null); setRevision((value) => value + 1); }
        }
    };
    const decide = (request, decision) => {
        if (!meeting.can_manage_join_requests || !['admitted', 'denied'].includes(decision) || busyRef.current || !available) return;
        if (decision === 'denied' && !window.confirm(`Reject the join request from ${request.display_name}?`)) return;
        return perform(`waiting:${request.reference}`, `${base}/waiting-room/requests/${request.reference}`, 'PATCH', {decision}, noticeKey(decision === 'admitted' ? 'meetingRoom.waitingRoom.admitted' : 'meetingRoom.panels.joinRequestRejected'));
    };
    const end = async () => {
        if (!meeting.can_end || busyRef.current || !available || !window.confirm('End this active meeting for everyone?')) return;
        busyRef.current = 'end'; setBusy('end'); setMessage(null);
        activePoll.current?.abort();
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(`/school-classes/${schoolClass.id}/meetings/${meeting.uuid}/end`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            if (!response.ok) throw new Error();
            if (mounted.current) setMessage(noticeKey('meetingRoom.panels.endRequestSent'));
            // The initiating host cannot depend on receiving its own Reverb event.
            // The endpoint is authoritative; only its successful response triggers
            // the existing persistent-session cleanup/navigation transaction.
            try { await onMeetingEnded?.(); } catch {}
        } catch {
            if (mounted.current) setMessage(noticeKey('meetingRoom.panels.endFailed'));
        } finally {
            busyRef.current = null;
            if (mounted.current) { setBusy(null); setRevision((value) => value + 1); }
        }
    };
    const decideScreenShare = async (request, decision) => {
        if (!meeting.can_manage_screen_share_requests || !['approved', 'rejected'].includes(decision) || busyRef.current || !available) return;
        const key = `screen-share:${request.reference}`;
        busyRef.current = key; setBusy(key); setMessage(null);
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(`${base}/screen-share-requests/${encodeURIComponent(request.reference)}`, {method: 'PATCH', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({decision})});
            if (!response.ok) throw new Error();
            if (mounted.current) setMessage(noticeKey(decision === 'approved' ? 'meetingRoom.screenShare.approvalSent' : 'meetingRoom.screenShare.rejectionSent'));
        } catch {
            if (mounted.current) setMessage(noticeKey(decision === 'approved' ? 'meetingRoom.screenShare.approvalFailed' : 'meetingRoom.screenShare.rejectionFailed'));
        } finally {
            busyRef.current = null;
            if (mounted.current) { setBusy(null); setRevision((value) => value + 1); }
        }
    };
    return {records, requests, screenShareRequests, waitingLoaded, rosterError, waitingError, screenShareError, message, busy, available, mute, remove, decide, decideScreenShare, end};
}

import {useCallback, useEffect, useRef, useState} from 'react';
import {echo} from '../../realtime/echo';
import {noticeKey} from '../../i18n/notice';
import {isActiveScreenShareRequest} from '../../Components/Meetings/LiveKit/screenShareApproval';

export default function useMeetingScreenShareApproval({meeting, schoolClass, connected, onNotice}) {
    const [current, setCurrent] = useState(null);
    const [requesting, setRequesting] = useState(false);
    const notified = useRef(new Set());
    const refreshGeneration = useRef(0);
    const base = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/screen-share-requests`;
    const enabled = connected && meeting.status === 'active' && meeting.requires_screen_share_approval;

    const refresh = useCallback(async () => {
        if (!enabled) return;
        const generation = ++refreshGeneration.current;
        try {
            const response = await fetch(base, {headers: {Accept: 'application/json'}, cache: 'no-store'});
            if (!response.ok) throw new Error();
            const data = await response.json();
            if (generation !== refreshGeneration.current) return;
            setCurrent(data.current);
        } catch {
            if (generation !== refreshGeneration.current) return;
            onNotice(noticeKey('meetingRoom.screenShare.requestFailed'));
        }
    }, [base, enabled, onNotice]);

    useEffect(() => {
        if (!enabled) { setCurrent(null); return; }
        refresh();
        const interval = window.setInterval(refresh, 15000);
        return () => {
            refreshGeneration.current += 1;
            window.clearInterval(interval);
        };
    }, [enabled, refresh]);

    useEffect(() => {
        if (!enabled || !echo) return;
        const channel = echo.private(`meetings.class.${schoolClass.id}`);
        const changed = ({meeting_uuid: meetingUuid}) => { if (meetingUuid === meeting.uuid) refresh(); };
        channel.listen('.meeting.screen-share-request-changed', changed);
        return () => channel.stopListening('.meeting.screen-share-request-changed', changed);
    }, [enabled, meeting.uuid, refresh, schoolClass.id]);

    useEffect(() => {
        if (!current?.reference || notified.current.has(`${current.reference}:${current.status}`)) return;
        if (current.status === 'approved') onNotice(noticeKey('meetingRoom.screenShare.approved'));
        if (current.status === 'rejected') onNotice(noticeKey('meetingRoom.screenShare.declined'));
        notified.current.add(`${current.reference}:${current.status}`);
    }, [current, onNotice]);

    const request = useCallback(async () => {
        if (!enabled || requesting || isActiveScreenShareRequest(current?.status)) return;
        setRequesting(true);
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(base, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error();
            setCurrent(data.request);
        } catch {
            onNotice(noticeKey('meetingRoom.screenShare.requestFailed'));
        } finally {
            setRequesting(false);
        }
    }, [base, current?.status, enabled, onNotice, requesting]);

    const complete = useCallback(async () => {
        if (!current?.reference || !isActiveScreenShareRequest(current.status)) return;
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(`${base}/${encodeURIComponent(current.reference)}`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error();
            setCurrent(data.request);
        } catch {
            onNotice(noticeKey('meetingRoom.screenShare.revokeFailed'));
        }
    }, [base, current, onNotice]);

    /**
     * Asks the server to re-check this approved request against authoritative
     * provider state, because track webhooks cannot be relied on to report that
     * a share actually started.
     *
     * Only the opaque request reference is sent, and the scope lives in the route
     * itself. No LiveKit room name, identity, participant SID, track SID, track
     * source or "I am sharing" claim is ever transmitted: the server derives all
     * provider identifiers from the Meeting and MeetingParticipant.
     *
     * A failure here never stops the local share. The bounded server retry and
     * the expiry job remain authoritative for the request's lifecycle.
     */
    const reconcile = useCallback(async () => {
        if (!enabled || !current?.reference) return;
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(`${base}/${encodeURIComponent(current.reference)}/reconcile`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            if (!response.ok) throw new Error();
            const data = await response.json().catch(() => ({}));
            if (data.reconciled) refresh();
        } catch {
            onNotice(noticeKey('meetingRoom.screenShare.reconcileFailed'));
        }
    }, [base, current?.reference, enabled, onNotice, refresh]);

    const status = isActiveScreenShareRequest(current?.status) ? current.status : null;
    return {status, current, requesting, request, complete, reconcile, refresh};
}

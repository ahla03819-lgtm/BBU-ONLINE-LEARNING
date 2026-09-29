import {Track} from 'livekit-client';

export const localCameraMirrorClass = '-scale-x-100';

export function localCameraTrackClass(trackRef) {
    return trackRef?.participant?.isLocal && trackRef.source === Track.Source.Camera ? localCameraMirrorClass : '';
}

// All view choices stay in the mounted room's React state; they never change grants.
// Labels are translation keys so the interface language can change without touching view state.
export const meetingViews = [
    {id: 'gallery', labelKey: 'meetingRoom.views.gallery', descriptionKey: 'meetingRoom.views.galleryDescription', icon: 'users'},
    {id: 'speaker', labelKey: 'meetingRoom.views.speaker', descriptionKey: 'meetingRoom.views.speakerDescription', icon: 'user'},
    {id: 'screen', labelKey: 'meetingRoom.views.screen', descriptionKey: 'meetingRoom.views.screenDescription', icon: 'screen'},
];

export function selectMeetingStage({view, cameras, screens, speakers, focusedIdentity}) {
    const focused = cameras.find((track) => track.participant.identity === focusedIdentity);
    // Shared content takes priority in either focus layout. Gallery remains an explicit choice.
    if (screens.length && view !== 'gallery') return {kind: 'screen', primary: screens[0]};
    if (view === 'gallery') return {kind: 'gallery', primary: null};
    const speaker = speakers.map((participant) => cameras.find((track) => track.participant.identity === participant.identity)).find(Boolean);
    return {kind: 'camera', primary: focused || speaker || cameras[0] || null};
}

export function sortRaisedParticipants(participants, raisedHands) {
    return [...participants].sort((a, b) => Number(Boolean(raisedHands[b.identity])) - Number(Boolean(raisedHands[a.identity])));
}

export async function participantConnectionKey(meetingUuid, identity) {
    if (!globalThis.crypto?.subtle || !identity) return null;
    const hash = await globalThis.crypto.subtle.digest('SHA-256', new TextEncoder().encode(`${meetingUuid}:${identity}`));
    return Array.from(new Uint8Array(hash), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

export function authorizedMeetingLink(meeting, schoolClass, origin) {
    try {
        const url = new URL(meeting.invite_url, origin);
        const expectedPath = `/collaboration/classes/${schoolClass.id}/meetings/${meeting.uuid}/lobby`;
        if (url.origin !== origin || url.pathname !== expectedPath || url.search || url.hash || url.username || url.password) return null;
        return url.href;
    } catch { return null; }
}

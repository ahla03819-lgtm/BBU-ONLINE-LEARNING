export function canShowMuteParticipant({canManageParticipants, participant, record, available = true}) {
    return Boolean(
        canManageParticipants
        && available
        && participant
        && !participant.isLocal
        && participant.isMicrophoneEnabled
        && record?.can_mute,
    );
}

export function muteParticipantUrl(base, reference) {
    return `${base}/participants/${encodeURIComponent(reference)}/mute`;
}

export function canStartMuteParticipant({available, canManageParticipants, record, busy}) {
    return Boolean(available && canManageParticipants && record?.can_mute && !busy);
}

export async function requestParticipantMute({fetcher = fetch, base, reference, csrf}) {
    return fetcher(muteParticipantUrl(base, reference), {
        method: 'PATCH',
        headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json', 'Content-Type': 'application/json'},
    });
}

export function muteResultTranslationKey(result, ok) {
    if (!ok && result === 'participant_not_present') return 'meetingRoom.panels.participantNotPresent';
    if (!ok) return 'meetingRoom.panels.muteFailed';
    if (result === 'already_muted') return 'meetingRoom.panels.alreadyMuted';
    if (result === 'no_active_microphone') return 'meetingRoom.panels.noActiveMicrophone';
    return 'meetingRoom.panels.participantMuted';
}

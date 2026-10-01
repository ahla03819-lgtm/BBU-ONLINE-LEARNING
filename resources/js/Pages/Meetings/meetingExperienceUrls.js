export function meetingExperienceUrls(schoolClassId, meetingUuid) {
    const base = `/collaboration/classes/${schoolClassId}/meetings/${meetingUuid}`;

    return {
        roomUrl: `${base}/room`,
        lobbyUrl: `${base}/lobby`,
    };
}

export async function completeConfirmedMeetingEnd({stopMedia, disconnectRoom, navigate}) {
    await stopMedia();

    try {
        await disconnectRoom();
    } catch {
        // Session navigation still needs to complete if LiveKit teardown fails.
    }

    await navigate();
}

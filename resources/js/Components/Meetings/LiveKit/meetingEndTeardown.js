export async function completeConfirmedMeetingEnd({stopMedia, disconnectRoom, navigate}) {
    await stopMedia();

    try {
        await disconnectRoom();
    } catch {
        // Session navigation still needs to complete if LiveKit teardown fails.
    }

    await navigate();
}

/**
 * The pathname of a meeting experience URL, independent of origin.
 */
export function meetingPath(url) {
    return new URL(url, 'http://meeting.local').pathname;
}

/**
 * Whether a terminal transition must actively navigate to the lobby.
 *
 * A client only navigates when it is actually sitting on the room page. When it
 * is not - for example a mini window, or a room page the client already left -
 * there is nothing to navigate away from, and the session is simply released.
 *
 * This is the single decision that separates "session released" from "session
 * released with the client left on a page that no longer renders the room".
 */
export function shouldNavigateToLobby({returnToLobby, currentPath, roomUrl}) {
    if (!returnToLobby) return false;

    return currentPath === meetingPath(roomUrl);
}

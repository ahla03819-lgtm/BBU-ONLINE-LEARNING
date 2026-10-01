// 'sharing' is the server state for an approval that became a real share. If the
// browser is not currently publishing (a reload, a reconnect), the student can
// press Start sharing again on the same approval instead of requesting anew.
const ACTIVE_REQUEST_STATUSES = ['pending', 'approved', 'sharing'];

export function isActiveScreenShareRequest(status) {
    return ACTIVE_REQUEST_STATUSES.includes(status);
}

export function screenShareButtonState({canShareDirectly, requiresApproval, isSharing, requestStatus, requesting}) {
    if (isSharing) return 'sharing';
    if (canShareDirectly || !requiresApproval) return 'ready';
    if (requesting) return 'requesting';
    if (requestStatus === 'pending') return 'pending';
    if (requestStatus === 'approved' || requestStatus === 'sharing') return 'approved';
    return 'request';
}

export function screenShareLabelKey(state) {
    return {
        sharing: 'meetingRoom.controlCenter.stopShare',
        ready: 'meetingRoom.controlCenter.share',
        requesting: 'meetingRoom.screenShare.requesting',
        pending: 'meetingRoom.screenShare.waiting',
        approved: 'meetingRoom.screenShare.start',
        request: 'meetingRoom.screenShare.request',
    }[state];
}

export function shouldStartScreenCapture(state) {
    return ['ready', 'approved', 'sharing'].includes(state);
}

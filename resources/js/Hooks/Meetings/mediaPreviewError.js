// Explicit extension so this pure module stays importable from the node test
// runner, matching i18n/localeSelection.js.
import {noticeKey} from '../../i18n/notice.js';

// Each error branch owns its own sentence. The result is a notice descriptor and
// the device is carried as a *key*, so the label is translated at render time and
// the message keeps following the locale while it is on screen.
export const friendlyMediaError = (error, deviceKey) => {
    const key = error?.name === 'NotAllowedError' || error?.name === 'SecurityError'
        ? 'meetingRoom.lobby.mediaError.permissionBlocked'
        : error?.name === 'NotFoundError'
            ? 'meetingRoom.lobby.mediaError.notFound'
            : error?.name === 'NotReadableError'
                ? 'meetingRoom.lobby.mediaError.inUse'
                : error?.name === 'OverconstrainedError'
                    ? 'meetingRoom.lobby.mediaError.unavailable'
                    : 'meetingRoom.lobby.mediaError.startFailed';

    return noticeKey(key, {device: (t) => t(deviceKey)});
};

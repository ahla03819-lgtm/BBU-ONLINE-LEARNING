const STORAGE_PREFIX = 'bbu:conversation-call-media-intent:v1';

export function conversationCallMediaPlan({callType, audioOnly = false, mediaIntent = null}) {
    const wantsVideo = callType === 'video' && !audioOnly;

    return {
        microphoneEnabled: mediaIntent?.microphoneEnabled ?? true,
        cameraEnabled: wantsVideo && (mediaIntent?.cameraEnabled ?? true),
        acquireCamera: wantsVideo && (mediaIntent?.cameraEnabled ?? true),
    };
}

export function conversationCallMediaOutcome({microphoneStatus, cameraStatus = null}) {
    const microphoneUnavailable = microphoneStatus === 'rejected';
    const cameraUnavailable = cameraStatus === 'rejected';

    // A catalogue key, never a rendered sentence: the component turns this into a
    // notice descriptor so the message is translated for the active locale and
    // follows a language switch while it is on screen.
    return {
        microphoneUnavailable,
        cameraUnavailable,
        messageKey: microphoneUnavailable
            ? 'conversations.micBlocked'
            : cameraUnavailable
                ? 'conversations.cameraBlocked'
                : null,
    };
}

export function conversationCallDurationSeconds(startedAt, now = Date.now(), serverClock = null) {
    const start = Date.parse(startedAt);
    if (!Number.isFinite(start)) return 0;

    const serverNow = Date.parse(serverClock?.serverNowAt);
    if (Number.isFinite(serverNow) && Number.isFinite(serverClock?.receivedAt)) {
        const elapsedAtReceipt = serverNow - start;
        return Math.max(0, Math.floor((elapsedAtReceipt + now - serverClock.receivedAt) / 1000));
    }

    return Math.max(0, Math.floor((now - start) / 1000));
}

export function conversationCallMediaIntentKey(callUuid, userId) {
    if (!callUuid || userId === null || userId === undefined) return null;

    return `${STORAGE_PREFIX}:${encodeURIComponent(String(callUuid))}:${encodeURIComponent(String(userId))}`;
}

export function readConversationCallMediaIntent(key) {
    try {
        const value = JSON.parse(globalThis.sessionStorage?.getItem(key) || 'null');
        if (value?.version !== 1) return null;

        return {
            microphoneEnabled: value.microphoneEnabled === true,
            cameraEnabled: value.cameraEnabled === true,
        };
    } catch {
        return null;
    }
}

export function writeConversationCallMediaIntent(key, changes) {
    if (!key) return false;

    try {
        const current = readConversationCallMediaIntent(key) || {microphoneEnabled: false, cameraEnabled: false};
        globalThis.sessionStorage?.setItem(key, JSON.stringify({
            version: 1,
            microphoneEnabled: typeof changes.microphoneEnabled === 'boolean' ? changes.microphoneEnabled : current.microphoneEnabled,
            cameraEnabled: typeof changes.cameraEnabled === 'boolean' ? changes.cameraEnabled : current.cameraEnabled,
        }));
        return true;
    } catch {
        return false;
    }
}

export function clearConversationCallMediaIntent(key) {
    try {
        if (key) globalThis.sessionStorage?.removeItem(key);
    } catch {
        return;
    }
}
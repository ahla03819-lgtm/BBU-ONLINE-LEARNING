const STORAGE_PREFIX = 'bbu:meeting-media-intent:v1';

const DEFAULT_INTENT = {
    microphoneEnabled: false,
    cameraEnabled: false,
    wasScreenSharing: false,
};

function getSessionStorage() {
    try {
        return globalThis.sessionStorage || null;
    } catch {
        return null;
    }
}

export function meetingMediaIntentKey(meetingUuid, userId) {
    if (!meetingUuid || userId === null || userId === undefined) return null;

    return `${STORAGE_PREFIX}:${encodeURIComponent(String(meetingUuid))}:${encodeURIComponent(String(userId))}`;
}

export function readMeetingMediaIntent(key) {
    const storage = getSessionStorage();
    if (!key || !storage) return null;

    try {
        const record = JSON.parse(storage.getItem(key) || 'null');
        if (!record || record.version !== 1) return null;

        return {
            microphoneEnabled: record.microphoneEnabled === true,
            cameraEnabled: record.cameraEnabled === true,
            wasScreenSharing: record.wasScreenSharing === true,
        };
    } catch {
        return null;
    }
}

export function writeMeetingMediaIntent(key, changes = {}) {
    const storage = getSessionStorage();
    if (!key || !storage) return false;

    const current = readMeetingMediaIntent(key) || DEFAULT_INTENT;
    const next = {version: 1, ...current};
    for (const field of Object.keys(DEFAULT_INTENT)) {
        if (typeof changes[field] === 'boolean') next[field] = changes[field];
    }

    try {
        storage.setItem(key, JSON.stringify(next));
        return true;
    } catch {
        return false;
    }
}

export function clearMeetingMediaIntent(key) {
    const storage = getSessionStorage();
    if (!key || !storage) return;

    try {
        storage.removeItem(key);
    } catch {
        return;
    }
}

export function screenShareIntentChange({enabled, wasEnabled, isUnmounting}) {
    if (isUnmounting) return null;
    if (enabled) return true;
    if (wasEnabled) return false;

    return null;
}
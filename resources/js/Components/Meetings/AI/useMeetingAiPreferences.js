/**
 * AI caption preference persistence.
 *
 * A small, dependency-free preference store that mirrors the conventions used
 * by `meetingMediaIntent.js`: a versioned JSON record, a scoped storage key,
 * and a read/validate/write cycle that never trusts untrusted input.
 *
 * The preference is deliberately narrow — it only owns the caption mode. It
 * does not know about media, LiveKit, or the meeting lifecycle, so it can be
 * unit-tested in plain Node and safely reused by any component that needs the
 * user's caption display choice.
 *
 * Storage namespace: `bbu:meeting-ai-preferences:<meeting>:<user>`
 */

const STORAGE_PREFIX = 'bbu:meeting-ai-preferences:v1';

/** The only caption modes the assistant understands. Order is significant: it
 *  is the canonical presentation order for the mode selector. */
export const CAPTION_MODES = Object.freeze(['off', 'en', 'km', 'bilingual']);

const DEFAULT_PREFERENCE = Object.freeze({version: 1, captionMode: 'off'});

const PREFERENCE_VERSION = 1;

function getSessionStorage() {
    try {
        return globalThis.sessionStorage || null;
    } catch {
        return null;
    }
}

/**
 * Validate a stored caption mode. Anything that is not one of the four known
 * modes degrades to `off` rather than throwing, so a corrupted preference can
 * never break the meeting room render.
 */
export function normalizeCaptionMode(value) {
    if (typeof value !== 'string') return 'off';
    const normalized = value.trim().toLowerCase();
    return CAPTION_MODES.includes(normalized) ? normalized : 'off';
}

export function isCaptionMode(value) {
    return typeof value === 'string' && CAPTION_MODES.includes(value.trim().toLowerCase());
}

/**
 * Build the scoped storage key for one user's caption preference in one
 * meeting. Returns null when the context is incomplete, so callers can short
 * circuit without inventing a key.
 */
export function meetingAiPreferencesKey(meetingUuid, userId) {
    if (!meetingUuid || userId === null || userId === undefined) return null;

    return `${STORAGE_PREFIX}:${encodeURIComponent(String(meetingUuid))}:${encodeURIComponent(String(userId))}`;
}

function parsePreference(raw) {
    if (!raw || typeof raw !== 'object') return null;
    if (raw.version !== PREFERENCE_VERSION) return null;

    return {
        version: PREFERENCE_VERSION,
        captionMode: normalizeCaptionMode(raw.captionMode),
    };
}

export function readMeetingAiPreferences(key) {
    const storage = getSessionStorage();
    if (!key || !storage) return DEFAULT_PREFERENCE;

    try {
        const record = JSON.parse(storage.getItem(key) || 'null');
        return parsePreference(record) || DEFAULT_PREFERENCE;
    } catch {
        return DEFAULT_PREFERENCE;
    }
}

export function writeMeetingAiPreferences(key, changes = {}) {
    const storage = getSessionStorage();
    if (!key || !storage) return false;

    const current = readMeetingAiPreferences(key);
    const next = {version: PREFERENCE_VERSION, ...current};

    if (typeof changes.captionMode === 'string') {
        next.captionMode = normalizeCaptionMode(changes.captionMode);
    }

    try {
        storage.setItem(key, JSON.stringify(next));
        return true;
    } catch {
        return false;
    }
}

export function clearMeetingAiPreferences(key) {
    const storage = getSessionStorage();
    if (!key || !storage) return;

    try {
        storage.removeItem(key);
    } catch {
        return;
    }
}

/** The default preference, frozen so callers cannot mutate the canonical
 *  fallback by accident. */
export function defaultAiPreferences() {
    return {...DEFAULT_PREFERENCE};
}

export default {
    CAPTION_MODES,
    meetingAiPreferencesKey,
    readMeetingAiPreferences,
    writeMeetingAiPreferences,
    clearMeetingAiPreferences,
    normalizeCaptionMode,
    isCaptionMode,
    defaultAiPreferences,
};
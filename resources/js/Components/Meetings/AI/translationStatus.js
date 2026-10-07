/**
 * Translation display contract.
 *
 * A small, deterministic status machine for the caption translation state.
 * It is intentionally display-only: it does not call any backend, it does not
 * make network requests, and it does not own Buddy state. It just classifies
 * the translation outcome for a single caption line so the overlay can render
 * the right fallback text without fabricating Khmer.
 *
 * Statuses: idle | pending | ready | unavailable | failed
 */

export const TRANSLATION_STATUSES = Object.freeze([
    'idle',
    'pending',
    'ready',
    'unavailable',
    'failed',
]);

const STATUS_SET = new Set(TRANSLATION_STATUSES);

export const isTranslationStatus = (value) => STATUS_SET.has(value);

/**
 * Classify the translation outcome for one caption line.
 *
 * The contract is conservative: a missing translatedText is never treated as
 * `ready`, and an explicit `unavailable`/`failed` status always wins over an
 * absent value. This keeps the overlay from manufacturing Khmer content when
 * the server simply has not sent it yet.
 *
 * @param {{status?: string, translatedText?: string | null} | string | null} input
 */
export function resolveTranslationStatus(input) {
    if (input === null || input === undefined) return 'idle';

    if (typeof input === 'string') {
        return isTranslationStatus(input) ? input : 'idle';
    }

    const {status, translatedText} = input;

    if (typeof status === 'string' && isTranslationStatus(status)) {
        // An explicit terminal status always wins: a failed translation is
        // still failed even if a stale body happens to be present.
        if (status === 'ready') {
            return typeof translatedText === 'string' && translatedText.trim().length > 0 ? 'ready' : 'unavailable';
        }
        return status;
    }

    if (typeof translatedText === 'string' && translatedText.trim().length > 0) {
        return 'ready';
    }

    return 'idle';
}

/** Build a display descriptor the overlay can render without further branching. */
export function translationDisplayDescriptor(input) {
    const status = resolveTranslationStatus(input);

    return {
        status,
        isReady: status === 'ready',
        isPending: status === 'pending',
        isUnavailable: status === 'unavailable',
        isFailed: status === 'failed',
        isIdle: status === 'idle',
    };
}

export default {
    TRANSLATION_STATUSES,
    isTranslationStatus,
    resolveTranslationStatus,
    translationDisplayDescriptor,
};
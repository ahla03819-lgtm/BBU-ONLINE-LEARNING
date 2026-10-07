/**
 * Caption selection contract.
 *
 * Pure, dependency-free helpers that decide what a caption overlay should
 * render for a given mode and translation state. Extracted so the contract can
 * be unit-tested in plain Node without mounting a component: the overlay
 * delegates here and the tests verify the same decisions.
 *
 * The contract is conservative:
 *   - `off` always renders nothing.
 *   - `en` selects the original text only.
 *   - `km` selects the translated text only, and never fabricates Khmer.
 *   - `bilingual` selects both, with the translation falling back to a status
 *     message when it is not ready.
 */

import {normalizeCaptionMode} from './useMeetingAiPreferences';
import {resolveTranslationStatus} from './translationStatus';

export function selectCaptionContent({
    mode = 'off',
    originalText,
    translatedText,
    translationStatus,
} = {}) {
    const captionMode = normalizeCaptionMode(mode);

    if (captionMode === 'off') {
        return {mode: 'off', showOriginal: false, showTranslation: false, hasOriginal: false, hasTranslation: false, status: 'idle', renders: false};
    }

    const status = resolveTranslationStatus({status: translationStatus, translatedText});
    const showOriginal = captionMode === 'en' || captionMode === 'bilingual';
    const showTranslation = captionMode === 'km' || captionMode === 'bilingual';
    const hasOriginal = typeof originalText === 'string' && originalText.trim().length > 0;
    const hasTranslation = status === 'ready';

    return {
        mode: captionMode,
        showOriginal,
        showTranslation,
        hasOriginal,
        hasTranslation,
        status,
        renders: (showOriginal && hasOriginal) || (showTranslation && (hasTranslation || status === 'pending' || status === 'unavailable' || status === 'failed')),
    };
}

export function isCaptionRendersNothing(selection) {
    return selection.mode === 'off' || !selection.renders;
}

export default {
    selectCaptionContent,
    isCaptionRendersNothing,
};
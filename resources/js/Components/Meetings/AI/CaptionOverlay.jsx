import React from 'react';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {normalizeCaptionMode} from './useMeetingAiPreferences';
import {resolveTranslationStatus} from './translationStatus';

/**
 * AI caption overlay.
 *
 * A reusable, LiveKit-independent caption display surface. It receives
 * transcript/caption data as props and renders nothing when captions are off,
 * so it can be dropped into any container without special-casing the empty
 * state. The parent owns positioning: this component is a plain block-level
 * element with no fixed or absolute positioning, so it adapts to stage,
 * fullscreen, screen-share or a mini window later.
 *
 * The overlay never fabricates Khmer. When `translatedText` is missing and the
 * translation is not yet ready, it renders the natural pending/unavailable
 * fallback instead of inventing content.
 */
const CAPTION_LOCALE_KEY = {
    off: 'off',
    en: 'english',
    km: 'khmer',
    bilingual: 'bilingual',
};

export function CaptionOverlay({
    mode = 'off',
    speakerName,
    originalText,
    translatedText,
    translationStatus,
    isVisible = true,
    className = '',
    children,
}) {
    const {t} = useTranslation();
    const captionMode = normalizeCaptionMode(mode);

    if (!isVisible || captionMode === 'off') return null;

    const status = resolveTranslationStatus({status: translationStatus, translatedText});
    const showOriginal = captionMode === 'en' || captionMode === 'bilingual';
    const showTranslation = captionMode === 'km' || captionMode === 'bilingual';
    const hasOriginal = typeof originalText === 'string' && originalText.trim().length > 0;
    const hasTranslation = status === 'ready';

    // If the mode asks for a language that has no content at all, there is
    // nothing meaningful to render — bail out rather than showing an empty
    // bubble or a misleading placeholder.
    if (!hasOriginal && !showTranslation) return null;
    if (!hasOriginal && !hasTranslation && status !== 'pending' && status !== 'unavailable' && status !== 'failed') {
        return null;
    }

    const translationLabel = status === 'pending'
        ? t('meetingRoom.ai.translationPending')
        : t('meetingRoom.ai.translationUnavailable');

    const modeLabel = t(`meetingRoom.ai.${CAPTION_LOCALE_KEY[captionMode]}`);

    return (
        <div
            className={`caption-overlay pointer-events-none ${className}`}
            aria-label={t('meetingRoom.ai.captions')}
            data-caption-mode={captionMode}
            data-translation-status={status}
        >
            <div className="mx-auto w-full max-w-3xl">
                <div className="rounded-2xl border border-white/15 bg-slate-900/85 px-4 py-3 shadow-xl shadow-black/40 backdrop-blur-sm">
                    {speakerName && (
                        <p className="mb-1.5 text-xs font-bold uppercase tracking-wide text-sky-300">
                            {speakerName}
                        </p>
                    )}

                    {showOriginal && hasOriginal && (
                        <p className="text-base font-medium leading-6 text-white">
                            {originalText}
                        </p>
                    )}

                    {showTranslation && (
                        <div className={showOriginal && hasOriginal ? 'mt-2 border-t border-white/10 pt-2' : ''}>
                            {hasTranslation ? (
                                <p className="text-base font-medium leading-6 text-amber-200">
                                    {translatedText}
                                </p>
                            ) : (
                                <p className="text-sm font-medium leading-6 text-amber-200/80">
                                    {translationLabel}
                                </p>
                            )}
                        </div>
                    )}

                    <p className="mt-2 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                        {modeLabel}
                    </p>
                </div>
                {children}
            </div>
        </div>
    );
}

CaptionOverlay.displayName = 'CaptionOverlay';

CaptionOverlay.defaultProps = {
    mode: 'off',
    isVisible: true,
};

export default CaptionOverlay;
import {useEffect, useRef, useState} from 'react';

export async function exitFullscreenAfterJoinFailure(fullscreenResult, exitFullscreen) {
    if (!fullscreenResult.ok) return;

    try {
        await exitFullscreen();
    } catch {
        // Preserve the original join failure even if the browser rejects cleanup.
    }
}

/**
 * Hook for managing browser fullscreen state for meetings.
 * Uses the Fullscreen API and listens to fullscreenchange events
 * to keep React state in sync with browser state.
 */
export default function useMeetingFullscreen() {
    const [isFullscreen, setIsFullscreen] = useState(false);
    const [fullscreenSupported, setFullscreenSupported] = useState(false);
    const elementRef = useRef(null);

    useEffect(() => {
        // Check if fullscreen is supported
        const supported = document.fullscreenEnabled === true;
        setFullscreenSupported(supported);

        // Set initial state
        setIsFullscreen(document.fullscreenElement !== null);

        const handleFullscreenChange = () => {
            setIsFullscreen(document.fullscreenElement !== null);
        };

        document.addEventListener('fullscreenchange', handleFullscreenChange);

        return () => {
            document.removeEventListener('fullscreenchange', handleFullscreenChange);
        };
    }, []);

    const enterFullscreen = async (element = document.documentElement) => {
        if (!fullscreenSupported) {
            return {ok: false, reason: 'unsupported'};
        }

        try {
            await element.requestFullscreen();
            return {ok: true};
        } catch (error) {
            // Fullscreen request was denied or failed
            // This is expected behavior - don't throw, just return failure
            return {ok: false, reason: error.name === 'TypeError' ? 'unsupported' : 'denied'};
        }
    };

    const exitFullscreen = async () => {
        if (!document.fullscreenElement) {
            return {ok: true};
        }

        try {
            await document.exitFullscreen();
            return {ok: true};
        } catch (error) {
            return {ok: false, reason: error.name};
        }
    };

    const toggleFullscreen = async (element = document.documentElement) => {
        if (isFullscreen) {
            return exitFullscreen();
        }
        return enterFullscreen(element);
    };

    return {
        isFullscreen,
        fullscreenSupported,
        enterFullscreen,
        exitFullscreen,
        toggleFullscreen,
        setElementRef: (el) => { elementRef.current = el; },
    };
}

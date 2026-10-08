import {useCallback, useEffect, useRef, useState} from 'react';
import {getNotes, getSummary, getTranscript, requestNotes, requestSummary} from './meetingAiApi';

const DEFAULT_STATE = {
    transcript: {segments: [], loading: false, error: null},
    notes: {note: null, loading: false, error: null},
    summary: {summary: null, loading: false, error: null},
};

export function useMeetingAiData({schoolClass, meeting, language = 'en'}) {
    const [state, setState] = useState(DEFAULT_STATE);
    const mounted = useRef(true);
    const notesTimerRef = useRef(null);
    const summaryTimerRef = useRef(null);

    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
            window.clearInterval(notesTimerRef.current);
            window.clearInterval(summaryTimerRef.current);
        };
    }, []);

    const setTranscript = (updater) => setState((current) => ({...current, transcript: typeof updater === 'function' ? updater(current.transcript) : {...current.transcript, ...updater}}));
    const setNotes = (updater) => setState((current) => ({...current, notes: typeof updater === 'function' ? updater(current.notes) : {...current.notes, ...updater}}));
    const setSummary = (updater) => setState((current) => ({...current, summary: typeof updater === 'function' ? updater(current.summary) : {...current.summary, ...updater}}));

    const loadTranscript = useCallback(async () => {
        if (!schoolClass || !meeting) return;
        setTranscript({loading: true, error: null});
        try {
            const data = await getTranscript(schoolClass, meeting);
            if (mounted.current) {
                setTranscript({segments: Array.isArray(data?.segments) ? data.segments : [], loading: false});
            }
        } catch (error) {
            if (mounted.current) {
                setTranscript({loading: false, error: error.message || 'Unable to load transcript.'});
            }
        }
    }, [schoolClass, meeting]);

    const loadNotes = useCallback(async () => {
        if (!schoolClass || !meeting) return;
        setNotes({loading: true, error: null});
        try {
            const data = await getNotes(schoolClass, meeting, language);
            if (mounted.current) {
                setNotes({note: data?.note ?? null, loading: false});
            }
        } catch (error) {
            if (mounted.current) {
                setNotes({loading: false, error: error.message || 'Unable to load notes.'});
            }
        }
    }, [schoolClass, meeting, language]);

    const loadSummary = useCallback(async () => {
        if (!schoolClass || !meeting) return;
        setSummary({loading: true, error: null});
        try {
            const data = await getSummary(schoolClass, meeting, language);
            if (mounted.current) {
                setSummary({summary: data?.summary ?? null, loading: false});
            }
        } catch (error) {
            if (mounted.current) {
                setSummary({loading: false, error: error.message || 'Unable to load summary.'});
            }
        }
    }, [schoolClass, meeting, language]);

    const stopNotesPolling = useCallback(() => {
        window.clearInterval(notesTimerRef.current);
        notesTimerRef.current = null;
    }, []);

    const startNotesPolling = useCallback(() => {
        stopNotesPolling();
        notesTimerRef.current = window.setInterval(async () => {
            if (!mounted.current || !schoolClass || !meeting) return;
            try {
                const data = await getNotes(schoolClass, meeting, language);
                const note = data?.note;
                if (!mounted.current) return;
                setNotes({note: note ?? null, loading: false});
                if (!note || ['ready', 'failed'].includes(note.status)) {
                    stopNotesPolling();
                }
            } catch (error) {
                if (mounted.current) {
                    setNotes({loading: false, error: error.message || 'Unable to refresh notes.'});
                    stopNotesPolling();
                }
            }
        }, 3000);
    }, [schoolClass, meeting, language, stopNotesPolling]);

    const generateNotes = useCallback(async () => {
        if (!schoolClass || !meeting) return;
        stopNotesPolling();
        setNotes({loading: true, error: null});
        try {
            const data = await requestNotes(schoolClass, meeting, language);
            const note = data?.note;
            if (mounted.current) {
                setNotes({note: note ?? null, loading: false});
                if (note && note.status === 'generating') {
                    startNotesPolling();
                }
            }
        } catch (error) {
            if (mounted.current) {
                setNotes({loading: false, error: error.message || 'Unable to request notes.'});
            }
        }
    }, [schoolClass, meeting, language, startNotesPolling, stopNotesPolling]);

    const stopSummaryPolling = useCallback(() => {
        window.clearInterval(summaryTimerRef.current);
        summaryTimerRef.current = null;
    }, []);

    const startSummaryPolling = useCallback(() => {
        stopSummaryPolling();
        summaryTimerRef.current = window.setInterval(async () => {
            if (!mounted.current || !schoolClass || !meeting) return;
            try {
                const data = await getSummary(schoolClass, meeting, language);
                const summary = data?.summary;
                if (!mounted.current) return;
                setSummary({summary: summary ?? null, loading: false});
                if (!summary || ['ready', 'failed'].includes(summary.status)) {
                    stopSummaryPolling();
                }
            } catch (error) {
                if (mounted.current) {
                    setSummary({loading: false, error: error.message || 'Unable to refresh summary.'});
                    stopSummaryPolling();
                }
            }
        }, 3000);
    }, [schoolClass, meeting, language, stopSummaryPolling]);

    const generateSummary = useCallback(async () => {
        if (!schoolClass || !meeting) return;
        stopSummaryPolling();
        setSummary({loading: true, error: null});
        try {
            const data = await requestSummary(schoolClass, meeting, language);
            const summary = data?.summary;
            if (mounted.current) {
                setSummary({summary: summary ?? null, loading: false});
                if (summary && summary.status === 'generating') {
                    startSummaryPolling();
                }
            }
        } catch (error) {
            if (mounted.current) {
                setSummary({loading: false, error: error.message || 'Unable to request summary.'});
            }
        }
    }, [schoolClass, meeting, language, startSummaryPolling, stopSummaryPolling]);

    return {
        transcript: state.transcript,
        notes: state.notes,
        summary: state.summary,
        loadTranscript,
        loadNotes,
        loadSummary,
        generateNotes,
        generateSummary,
    };
}

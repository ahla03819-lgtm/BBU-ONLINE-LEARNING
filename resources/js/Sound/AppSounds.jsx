import React, {createContext, useCallback, useContext, useEffect, useMemo, useState} from 'react';

const preferenceKey = 'bbu.app-sounds.enabled';
const recentEvents = new Map();
let audioContext = null;
let unlocked = false;

const definitions = {
    notification: {notes: [[740, 0, 0.055], [988, 0.085, 0.075]], cooldown: 900},
    'waiting-room-request': {notes: [[587, 0, 0.065], [784, 0.105, 0.08]], cooldown: 1200},
    admitted: {notes: [[523, 0, 0.06], [659, 0.075, 0.065], [784, 0.15, 0.09]], cooldown: 1200},
    'participant-joined': {notes: [[660, 0, 0.06]], cooldown: 900},
    'participant-left': {notes: [[392, 0, 0.07]], cooldown: 900},
    'meeting-ended': {notes: [[494, 0, 0.08], [370, 0.1, 0.11]], cooldown: 1600},
    reconnect: {notes: [[440, 0, 0.06], [440, 0.1, 0.06]], cooldown: 1800},
    'incoming-call': {notes: [[523, 0, 0.12], [659, 0.18, 0.12]], cooldown: 1500},
};

const readPreference = () => {
    try {
        return window.localStorage.getItem(preferenceKey) === 'true';
    } catch {
        return false;
    }
};

const context = () => {
    if (!audioContext && typeof window !== 'undefined') {
        const AudioContext = window.AudioContext || window.webkitAudioContext;
        if (AudioContext) audioContext = new AudioContext();
    }

    return audioContext;
};

const unlockAudio = () => {
    const audio = context();
    if (!audio) return;
    audio.resume().then(() => { unlocked = audio.state === 'running'; }).catch(() => {});
};

const shouldPlay = (key, cooldown) => {
    const now = Date.now();
    const previous = recentEvents.get(key);
    if (previous && now - previous < cooldown) return false;
    recentEvents.set(key, now);
    if (recentEvents.size > 100) recentEvents.delete(recentEvents.keys().next().value);
    return true;
};

const playTone = (name, eventId = name) => {
    const definition = definitions[name];
    const audio = context();
    if (!definition || !audio || !unlocked || !readPreference() || !shouldPlay(`${name}:${eventId}`, definition.cooldown)) return false;

    try {
        const now = audio.currentTime;
        definition.notes.forEach(([frequency, offset, duration]) => {
            const gain = audio.createGain();
            const oscillator = audio.createOscillator();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(frequency, now + offset);
            gain.gain.setValueAtTime(0.0001, now + offset);
            gain.gain.exponentialRampToValueAtTime(0.035, now + offset + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + duration);
            oscillator.connect(gain).connect(audio.destination);
            oscillator.start(now + offset);
            oscillator.stop(now + offset + duration + 0.02);
        });
        return true;
    } catch {
        return false;
    }
};

const AppSoundsContext = createContext(null);

export function AppSoundsProvider({children}) {
    const [enabled, setEnabled] = useState(readPreference);

    useEffect(() => {
        const activate = () => unlockAudio();
        window.addEventListener('pointerdown', activate, {once: true});
        window.addEventListener('keydown', activate, {once: true});
        return () => {
            window.removeEventListener('pointerdown', activate);
            window.removeEventListener('keydown', activate);
        };
    }, []);

    const setSoundEnabled = useCallback((value) => {
        const next = Boolean(value);
        try { window.localStorage.setItem(preferenceKey, String(next)); } catch {}
        setEnabled(next);
        if (next) unlockAudio();
    }, []);
    const play = useCallback((name, eventId) => playTone(name, eventId), []);
    const value = useMemo(() => ({enabled, setEnabled: setSoundEnabled, play}), [enabled, play, setSoundEnabled]);

    return <AppSoundsContext.Provider value={value}>{children}</AppSoundsContext.Provider>;
}

export function useAppSounds() {
    return useContext(AppSoundsContext) || {enabled: false, setEnabled: () => {}, play: () => false};
}

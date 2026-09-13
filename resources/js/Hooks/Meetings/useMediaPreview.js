import {useCallback, useEffect, useRef, useState} from 'react';

const friendlyMediaError = (error, device) => {
    if (error?.name === 'NotAllowedError' || error?.name === 'SecurityError') return `${device} permission was blocked. Allow access in your browser settings, then try again.`;
    if (error?.name === 'NotFoundError') return `No ${device.toLowerCase()} was found. Connect one and try again.`;
    if (error?.name === 'NotReadableError') return `${device} is being used by another application. Close it there, then try again.`;
    if (error?.name === 'OverconstrainedError') return `The selected ${device.toLowerCase()} is no longer available. Choose another device.`;

    return `We could not start your ${device.toLowerCase()}. Please try again.`;
};

const labelFor = (device, fallback, index) => device.label || `${fallback} ${index + 1}`;

export default function useMediaPreview() {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const meterContextRef = useRef(null);
    const meterFrameRef = useRef(null);
    const speakerRef = useRef(null);
    const [devices, setDevices] = useState({cameras: [], microphones: [], speakers: []});
    const [cameraId, setCameraId] = useState('');
    const [microphoneId, setMicrophoneId] = useState('');
    const [speakerId, setSpeakerId] = useState('');
    const [cameraEnabled, setCameraEnabled] = useState(false);
    const [microphoneEnabled, setMicrophoneEnabled] = useState(false);
    const [error, setError] = useState('');
    const [microphoneLevel, setMicrophoneLevel] = useState(0);
    const [testingMicrophone, setTestingMicrophone] = useState(false);
    const [testingSpeaker, setTestingSpeaker] = useState(false);
    const speakerSelectionSupported = typeof HTMLMediaElement !== 'undefined' && typeof HTMLMediaElement.prototype.setSinkId === 'function';

    const stopMeter = useCallback(() => {
        if (meterFrameRef.current) cancelAnimationFrame(meterFrameRef.current);
        meterFrameRef.current = null;
        meterContextRef.current?.close?.().catch(() => {});
        meterContextRef.current = null;
        setTestingMicrophone(false);
        setMicrophoneLevel(0);
    }, []);

    const stop = useCallback(() => {
        stopMeter();
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        if (videoRef.current) videoRef.current.srcObject = null;
        if (speakerRef.current) {
            speakerRef.current.pause();
            speakerRef.current.removeAttribute('src');
            speakerRef.current.load();
        }
    }, [stopMeter]);

    const enumerate = useCallback(async () => {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        try {
            const list = await navigator.mediaDevices.enumerateDevices();
            setDevices({
                cameras: list.filter((item) => item.kind === 'videoinput'),
                microphones: list.filter((item) => item.kind === 'audioinput'),
                speakers: list.filter((item) => item.kind === 'audiooutput'),
            });
        } catch {
            // Device lists are an enhancement; access errors are shown when a device is used.
        }
    }, []);

    const prepare = useCallback(async (nextCamera = cameraEnabled, nextMicrophone = microphoneEnabled, nextCameraId = cameraId, nextMicrophoneId = microphoneId) => {
        if (!navigator.mediaDevices?.getUserMedia) {
            setError('Media devices are unavailable in this browser.');
            return false;
        }
        stop();
        setError('');
        if (!nextCamera && !nextMicrophone) return true;

        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: nextCamera ? {deviceId: nextCameraId ? {exact: nextCameraId} : undefined} : false,
                audio: nextMicrophone ? {deviceId: nextMicrophoneId ? {exact: nextMicrophoneId} : undefined} : false,
            });
            streamRef.current = stream;
            if (videoRef.current) videoRef.current.srcObject = stream;
            await enumerate();
            return true;
        } catch (problem) {
            setCameraEnabled(false);
            setMicrophoneEnabled(false);
            setError(friendlyMediaError(problem, nextCamera && nextMicrophone ? 'camera or microphone' : nextCamera ? 'Camera' : 'Microphone'));
            return false;
        }
    }, [cameraEnabled, microphoneEnabled, cameraId, microphoneId, enumerate, stop]);

    const toggleCamera = useCallback(async () => {
        const next = !cameraEnabled;
        const prepared = await prepare(next, microphoneEnabled);
        if (prepared) setCameraEnabled(next);
    }, [cameraEnabled, microphoneEnabled, prepare]);

    const toggleMicrophone = useCallback(async () => {
        const next = !microphoneEnabled;
        const prepared = await prepare(cameraEnabled, next);
        if (prepared) setMicrophoneEnabled(next);
    }, [cameraEnabled, microphoneEnabled, prepare]);

    const chooseCamera = useCallback(async (nextId) => {
        const prepared = await prepare(cameraEnabled, microphoneEnabled, nextId, microphoneId);
        if (prepared) setCameraId(nextId);
    }, [cameraEnabled, microphoneEnabled, microphoneId, prepare]);

    const chooseMicrophone = useCallback(async (nextId) => {
        const prepared = await prepare(cameraEnabled, microphoneEnabled, cameraId, nextId);
        if (prepared) setMicrophoneId(nextId);
    }, [cameraEnabled, microphoneEnabled, cameraId, prepare]);

    const testMicrophone = useCallback(async () => {
        if (testingMicrophone) {
            stopMeter();
            return;
        }
        let stream = streamRef.current;
        if (!stream?.getAudioTracks().length) {
            const ready = await prepare(cameraEnabled, true);
            if (!ready) return;
            stream = streamRef.current;
            setMicrophoneEnabled(true);
        }
        const Context = window.AudioContext || window.webkitAudioContext;
        if (!Context || !stream) {
            setError('Microphone testing is unavailable in this browser.');
            return;
        }
        stopMeter();
        const context = new Context();
        const analyser = context.createAnalyser();
        analyser.fftSize = 256;
        context.createMediaStreamSource(stream).connect(analyser);
        meterContextRef.current = context;
        const samples = new Uint8Array(analyser.frequencyBinCount);
        const draw = () => {
            analyser.getByteTimeDomainData(samples);
            const average = samples.reduce((sum, sample) => sum + Math.abs(sample - 128), 0) / samples.length;
            setMicrophoneLevel(Math.min(100, Math.round(average * 2.6)));
            meterFrameRef.current = requestAnimationFrame(draw);
        };
        draw();
        setTestingMicrophone(true);
    }, [cameraEnabled, prepare, stopMeter, testingMicrophone]);

    const testSpeaker = useCallback(async () => {
        const audio = speakerRef.current;
        if (!audio) return;
        setError('');
        try {
            if (speakerSelectionSupported && speakerId) await audio.setSinkId(speakerId);
            audio.src = 'data:audio/wav;base64,UklGRl4AAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YToAAAAAAB4eOTlPT0M5Hh4AAAAeHjk5T09DOx4eAAAAHh45OU9PQzseHgAAAB4eOTlPT0M7Hh4AAAA=';
            setTestingSpeaker(true);
            await audio.play();
            window.setTimeout(() => setTestingSpeaker(false), 900);
        } catch {
            setTestingSpeaker(false);
            setError('We could not play a speaker test. Check your output device and try again.');
        }
    }, [speakerId, speakerSelectionSupported]);

    useEffect(() => {
        enumerate();
        const changed = () => enumerate();
        navigator.mediaDevices?.addEventListener?.('devicechange', changed);
        return () => {
            stop();
            navigator.mediaDevices?.removeEventListener?.('devicechange', changed);
        };
    }, [enumerate, stop]);

    return {
        videoRef, speakerRef, devices, cameraId, microphoneId, speakerId, cameraEnabled, microphoneEnabled,
        chooseCamera, chooseMicrophone, setSpeakerId, toggleCamera, toggleMicrophone, testMicrophone,
        testSpeaker, microphoneLevel, testingMicrophone, testingSpeaker, speakerSelectionSupported,
        labels: {
            camera: (device, index) => labelFor(device, 'Camera', index),
            microphone: (device, index) => labelFor(device, 'Microphone', index),
            speaker: (device, index) => labelFor(device, 'Speaker', index),
        },
        error, stop,
    };
}

import {useCallback, useEffect, useRef, useState} from 'react';

export default function useMediaPreview() {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const [devices, setDevices] = useState({cameras: [], microphones: []});
    const [cameraId, setCameraId] = useState('');
    const [microphoneId, setMicrophoneId] = useState('');
    const [cameraEnabled, setCameraEnabled] = useState(false);
    const [microphoneEnabled, setMicrophoneEnabled] = useState(false);
    const [error, setError] = useState('');

    const stop = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        if (videoRef.current) videoRef.current.srcObject = null;
    }, []);

    const enumerate = useCallback(async () => {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        const list = await navigator.mediaDevices.enumerateDevices();
        setDevices({cameras: list.filter((item) => item.kind === 'videoinput'), microphones: list.filter((item) => item.kind === 'audioinput')});
    }, []);

    const prepare = useCallback(async (nextCamera = cameraEnabled, nextMicrophone = microphoneEnabled) => {
        if (!navigator.mediaDevices?.getUserMedia) { setError('Media devices are unavailable in this browser.'); return; }
        stop(); setError('');
        if (!nextCamera && !nextMicrophone) return;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: nextCamera ? {deviceId: cameraId ? {exact: cameraId} : undefined} : false,
                audio: nextMicrophone ? {deviceId: microphoneId ? {exact: microphoneId} : undefined} : false,
            });
            streamRef.current = stream;
            if (videoRef.current) videoRef.current.srcObject = stream;
            await enumerate();
        } catch { setError('Camera or microphone permission was denied, or the selected device is unavailable.'); }
    }, [cameraEnabled, microphoneEnabled, cameraId, microphoneId, stop, enumerate]);

    useEffect(() => { enumerate(); const changed = () => enumerate(); navigator.mediaDevices?.addEventListener?.('devicechange', changed); return () => { stop(); navigator.mediaDevices?.removeEventListener?.('devicechange', changed); }; }, [enumerate, stop]);
    const toggleCamera = async () => { const next = !cameraEnabled; setCameraEnabled(next); await prepare(next, microphoneEnabled); };
    const toggleMicrophone = async () => { const next = !microphoneEnabled; setMicrophoneEnabled(next); await prepare(cameraEnabled, next); };
    useEffect(() => { if (cameraEnabled || microphoneEnabled) prepare(); }, [cameraId, microphoneId]);

    return {videoRef, devices, cameraId, setCameraId, microphoneId, setMicrophoneId, cameraEnabled, microphoneEnabled, toggleCamera, toggleMicrophone, error, stop};
}

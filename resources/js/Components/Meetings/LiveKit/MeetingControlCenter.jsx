import React, {useEffect, useRef, useState} from 'react';
import {TrackToggle, useConnectionState, useLocalParticipant, useRoomContext, useTrackToggle} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';

function DeviceSelect({kind, label, icon, onMessage}) {
    const room = useRoomContext();
    const [open, setOpen] = useState(false);
    const [devices, setDevices] = useState([]);
    const ref = useRef(null);
    useEffect(() => {
        const refresh = () => navigator.mediaDevices?.enumerateDevices?.().then((items) => setDevices(items.filter((item) => item.kind === kind))).catch(() => {});
        refresh();
        navigator.mediaDevices?.addEventListener?.('devicechange', refresh);
        const close = (event) => { if (!ref.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('mousedown', close);
        return () => { navigator.mediaDevices?.removeEventListener?.('devicechange', refresh); document.removeEventListener('mousedown', close); };
    }, [kind]);
    const choose = async (event) => {
        try { await room.switchActiveDevice(kind, event.target.value, true); setOpen(false); onMessage(`${label} device updated.`); } catch { onMessage(`Unable to switch ${label.toLowerCase()} device.`); }
    };
    return <div className="relative" ref={ref}><button type="button" onClick={() => setOpen((value) => !value)} className="rounded-lg p-1.5 text-slate-300 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={`Choose ${label.toLowerCase()} device`} aria-expanded={open}><Icon name="chevron-down" className="h-3.5 w-3.5"/></button>{open && <div className="absolute bottom-[calc(100%+.6rem)] left-1/2 z-30 w-64 -translate-x-1/2 rounded-2xl border border-white/10 bg-[#171a23] p-3 shadow-2xl"><p className="mb-2 text-xs font-bold text-white">{label}</p><label className="sr-only" htmlFor={`${kind}-device`}>Choose {label.toLowerCase()}</label><select id={`${kind}-device`} defaultValue={room.getActiveDevice(kind) || ''} onChange={choose} className="w-full rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm text-white outline-none focus:ring-2 focus:ring-violet-400">{devices.map((device, index) => <option className="text-slate-900" key={device.deviceId} value={device.deviceId}>{device.label || `${label} ${index + 1}`}</option>)}</select></div>}</div>;
}

function Control({active, danger = false, children, ...props}) {
    return <button type="button" {...props} className={`inline-flex min-h-16 min-w-14 flex-col items-center justify-center gap-1 rounded-xl border px-2 py-2 text-[10px] font-bold transition focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:cursor-not-allowed disabled:opacity-45 ${danger ? 'border-rose-400/35 bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-300' : active ? 'border-violet-300/60 bg-violet-600 text-white shadow-[0_0_0_2px_rgba(139,92,246,.4)]' : 'border-white/[.12] bg-white/[.1] text-white hover:border-white/20 hover:bg-white/[.18]'}`}>
        {children}
    </button>;
}

export default function MeetingControlCenter({meeting, schoolClass, activePanel, onPanelChange, signals, onLeave, onMessage}) {
    const connection = useConnectionState();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled, isScreenShareEnabled} = useLocalParticipant();
    const share = useTrackToggle({source: Track.Source.ScreenShare, onDeviceError: () => onMessage('Screen sharing was cancelled or is unavailable in this browser.')});
    const [reactionOpen, setReactionOpen] = useState(false);
    const [moreOpen, setMoreOpen] = useState(false);
    const [ending, setEnding] = useState(false);
    const available = connection === 'connected' && meeting.status === 'active';
    const ref = useRef(null);
    const stopAll = async () => Promise.allSettled([localParticipant.setCameraEnabled(false), localParticipant.setMicrophoneEnabled(false), localParticipant.setScreenShareEnabled(false)]);
    useEffect(() => { if (['ending', 'ended', 'cancelled'].includes(meeting.status)) stopAll().finally(onLeave); }, [meeting.status]);
    useEffect(() => () => { localParticipant.setScreenShareEnabled(false).catch(() => {}); }, [localParticipant]);
    useEffect(() => {
        const close = (event) => { if (!ref.current?.contains(event.target)) { setReactionOpen(false); setMoreOpen(false); } };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);
    const leave = async () => { await stopAll(); onLeave(); };
    const endMeeting = async () => {
        if (!meeting.can_end || ending || !window.confirm('End this active meeting for everyone?')) return;
        setEnding(true);
        try {
            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            const response = await fetch(`/school-classes/${schoolClass.id}/meetings/${meeting.uuid}/end`, {method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
            if (!response.ok) throw new Error();
            onMessage('End meeting request sent.');
        } catch { onMessage('Unable to end this meeting. Please try again.'); }
        finally { setEnding(false); setMoreOpen(false); }
    };
    const togglePanel = (panel) => onPanelChange(activePanel === panel ? null : panel);

    return <div ref={ref} className="relative mx-auto mt-4 flex w-fit max-w-full flex-wrap items-center justify-center gap-2 rounded-2xl border border-white/[.08] bg-[#10131c]/95 p-2.5 shadow-2xl shadow-black/40">
        <Control active={activePanel === 'chat'} onClick={() => togglePanel('chat')} aria-label="Open meeting chat" aria-pressed={activePanel === 'chat'}><Icon name="messages" className="h-5 w-5"/>Chat</Control>
        <Control active={activePanel === 'people'} onClick={() => togglePanel('people')} aria-label="Open participants" aria-pressed={activePanel === 'people'}><Icon name="users" className="h-5 w-5"/>People</Control>
        <Control active={signals.localHandRaised} disabled={!available} onClick={() => signals.toggleHand().catch((error) => onMessage(error.message))} aria-label={signals.localHandRaised ? 'Lower hand' : 'Raise hand'} aria-pressed={signals.localHandRaised}><Icon name="hand" className="h-5 w-5"/>{signals.localHandRaised ? 'Lower' : 'Raise'}</Control>
        <div className="relative"><Control active={reactionOpen} disabled={!available} onClick={() => setReactionOpen((value) => !value)} aria-label="Open meeting reactions" aria-expanded={reactionOpen}><Icon name="smile" className="h-5 w-5"/>React</Control>{reactionOpen && <div className="absolute bottom-[calc(100%+.75rem)] left-1/2 z-30 flex -translate-x-1/2 gap-1 rounded-2xl border border-white/10 bg-[#171a23] p-2 shadow-2xl">{['👍', '❤️', '👏', '😂', '😮'].map((reaction) => <button key={reaction} type="button" onClick={() => { signals.sendReaction(reaction).catch((error) => onMessage(error.message)); setReactionOpen(false); }} className="rounded-xl p-2 text-xl transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={`Send ${reaction} reaction`}>{reaction}</button>)}</div>}</div>
        <div className="flex items-center rounded-xl border border-white/[.12] bg-white/[.1]"><TrackToggle source={Track.Source.Camera} showIcon={false} disabled={!available} className="inline-flex min-h-16 min-w-14 flex-col items-center justify-center gap-1 px-2 py-2 text-[10px] font-bold text-white transition hover:bg-white/[.08] focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={isCameraEnabled ? 'Turn camera off' : 'Turn camera on'} aria-pressed={isCameraEnabled}><Icon name={isCameraEnabled ? 'video' : 'video-off'} className="h-5 w-5"/>{isCameraEnabled ? 'Camera' : 'Camera off'}</TrackToggle><DeviceSelect kind="videoinput" label="Camera" onMessage={onMessage}/></div>
        <div className="flex items-center rounded-xl border border-white/[.12] bg-white/[.1]"><TrackToggle source={Track.Source.Microphone} showIcon={false} disabled={!available} className="inline-flex min-h-16 min-w-14 flex-col items-center justify-center gap-1 px-2 py-2 text-[10px] font-bold text-white transition hover:bg-white/[.08] focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={isMicrophoneEnabled ? 'Mute microphone' : 'Unmute microphone'} aria-pressed={isMicrophoneEnabled}><Icon name={isMicrophoneEnabled ? 'mic' : 'mic-off'} className="h-5 w-5"/>{isMicrophoneEnabled ? 'Mic' : 'Muted'}</TrackToggle><DeviceSelect kind="audioinput" label="Microphone" onMessage={onMessage}/></div>
        {meeting.can_screen_share && <Control active={isScreenShareEnabled} disabled={!available || share.pending} onClick={share.buttonProps.onClick} aria-label={isScreenShareEnabled ? 'Stop sharing screen' : 'Share screen'} aria-pressed={isScreenShareEnabled}><Icon name="screen" className="h-5 w-5"/>{isScreenShareEnabled ? 'Stop share' : 'Share'}</Control>}
        <div className="relative"><Control active={moreOpen} onClick={() => setMoreOpen((value) => !value)} aria-label="More meeting controls" aria-expanded={moreOpen}><Icon name="more" className="h-5 w-5"/>More</Control>{moreOpen && <div className="absolute bottom-[calc(100%+.75rem)] right-0 z-30 w-60 rounded-2xl border border-white/10 bg-[#171a23] p-2 shadow-2xl" role="menu"><div className="rounded-xl px-3 py-2 text-xs leading-5 text-slate-300"><p className="font-bold text-white">Meeting info</p><p className="mt-1">{meeting.title}</p></div>{meeting.can_end && <button type="button" disabled={ending} onClick={endMeeting} className="mt-1 flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-bold text-rose-200 transition hover:bg-rose-500/15 disabled:opacity-50" role="menuitem"><Icon name="power" className="h-4 w-4"/>{ending ? 'Ending…' : 'End meeting'}</button>}</div>}</div>
        <Control danger disabled={connection === 'disconnected'} onClick={leave} aria-label="Leave meeting"><Icon name="logout" className="h-5 w-5"/>Leave</Control>
    </div>;
}

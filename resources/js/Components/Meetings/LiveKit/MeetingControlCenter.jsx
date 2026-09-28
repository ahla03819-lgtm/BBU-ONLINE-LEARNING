import React, {useCallback, useEffect, useId, useRef, useState} from 'react';
import {TrackToggle, useConnectionState, useLocalParticipant, useRoomContext, useTrackToggle} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import {MeetingSidePanel} from './MeetingSidePanel';
import {meetingViews} from './meetingView';
import {readMeetingMediaIntent, screenShareIntentChange, writeMeetingMediaIntent} from './meetingMediaIntent';

function DeviceSelect({kind, label, onMessage, expanded = false}) {
    const id = useId();
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
    return <div className="relative" ref={ref} onKeyDown={(event) => { if (event.key === 'Escape' && open) { event.stopPropagation(); setOpen(false); ref.current?.querySelector('button')?.focus(); } }}>{!expanded && <button type="button" onClick={() => setOpen((value) => !value)} className="rounded-lg p-1.5 text-slate-300 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={`Choose ${label.toLowerCase()} device`} aria-expanded={open}><Icon name="chevron-down" className="h-3.5 w-3.5"/></button>}{(open || expanded) && <div className={expanded ? "rounded-2xl bg-slate-800 p-3" : "fixed inset-x-4 bottom-24 z-50 rounded-2xl border border-white/10 bg-[#171a23] p-3 shadow-2xl sm:absolute sm:inset-x-auto sm:bottom-[calc(100%+.6rem)] sm:left-0 sm:w-60"}><p className="mb-2 text-xs font-bold text-white">{label}</p><label className="sr-only" htmlFor={id}>Choose {label.toLowerCase()}</label><select id={id} defaultValue={room.getActiveDevice(kind) || ''} onChange={choose} className="w-full rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm text-white outline-none focus:ring-2 focus:ring-violet-400">{devices.map((device, index) => <option className="text-slate-900" key={device.deviceId} value={device.deviceId}>{device.label || `${label} ${index + 1}`}</option>)}</select></div>}</div>;
}

function Control({active, danger = false, wide = false, leave = false, className = '', children, ...props}) {
    const widthClass = leave ? 'min-w-[100px]' : wide ? 'min-w-[140px]' : 'min-w-[92px]';
    return <button type="button" {...props} className={`inline-flex min-h-[68px] ${widthClass} flex-col items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:cursor-not-allowed disabled:opacity-45 ${danger ? 'border-rose-400/35 bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-300' : active ? 'border-violet-300/60 bg-violet-600 text-white shadow-[0_0_0_2px_rgba(139,92,246,.4)]' : 'border-white/[.12] bg-white/[.1] text-white hover:border-white/20 hover:bg-white/[.18]'} ${className}`}>
        {children}
    </button>;
}

export function MeetingDeviceSettings({onClose, onMessage}) {
    return <MeetingSidePanel title="Device settings" icon="settings" onClose={onClose}><div className="space-y-4 overflow-y-auto p-4"><p className="text-sm text-slate-600">Choose the camera and microphone for this meeting.</p><DeviceSelect expanded kind="videoinput" label="Camera" onMessage={onMessage}/><DeviceSelect expanded kind="audioinput" label="Microphone" onMessage={onMessage}/></div></MeetingSidePanel>;
}

export default function MeetingControlCenter({meeting, activePanel, onPanelChange, signals, waitingCount, raisedCount, view, onViewChange, onCopyLink, copied, hasMeetingLink, mediaIntent, mediaIntentKey, mediaReady, onLeave, onMessage}) {
    const connection = useConnectionState();
    const room = useRoomContext();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled, isScreenShareEnabled} = useLocalParticipant();
    const [resumeScreenShare, setResumeScreenShare] = useState(() => readMeetingMediaIntent(mediaIntentKey)?.wasScreenSharing ?? mediaIntent?.wasScreenSharing === true);
    const screenShareWasEnabled = useRef(isScreenShareEnabled);
    const leavingPage = useRef(false);
    const screenShareChanged = useCallback((enabled, isUserInitiated) => {
        const wasEnabled = screenShareWasEnabled.current;
        screenShareWasEnabled.current = enabled;
        const intent = screenShareIntentChange({enabled, isUserInitiated, wasEnabled, isUnmounting: leavingPage.current});
        if (intent !== null) writeMeetingMediaIntent(mediaIntentKey, {wasScreenSharing: intent});
        if (enabled || intent === false) setResumeScreenShare(false);
    }, [mediaIntentKey]);
    const share = useTrackToggle({source: Track.Source.ScreenShare, captureOptions: {contentHint: 'text'}, onChange: screenShareChanged, onDeviceError: () => onMessage('Screen sharing was cancelled or is unavailable in this browser.')});
    const [popover, setPopover] = useState(null);
    const [leaving, setLeaving] = useState(false);
    const available = connection === 'connected' && meeting.status === 'active';
    const canHost = meeting.can_end || meeting.can_manage_participants || meeting.can_manage_join_requests;
    const ref = useRef(null);
    const menuRef = useRef(null);
    const trigger = useRef(null);
    const stopAll = async () => Promise.allSettled([localParticipant.setCameraEnabled(false), localParticipant.setMicrophoneEnabled(false), localParticipant.setScreenShareEnabled(false)]);
    useEffect(() => { if (['ending', 'ended', 'cancelled'].includes(meeting.status)) stopAll().finally(onLeave); }, [meeting.status]);
    useEffect(() => {
        const markLeavingPage = () => { leavingPage.current = true; };
        const resumePage = () => { leavingPage.current = false; };
        window.addEventListener('pagehide', markLeavingPage);
        window.addEventListener('pageshow', resumePage);
        return () => {
            window.removeEventListener('pagehide', markLeavingPage);
            window.removeEventListener('pageshow', resumePage);
        };
    }, []);
    useEffect(() => {
        const close = (event) => { if (!ref.current?.contains(event.target)) setPopover(null); };
        document.addEventListener('pointerdown', close);
        return () => document.removeEventListener('pointerdown', close);
    }, []);
    useEffect(() => { if (popover) menuRef.current?.querySelector('button:not(:disabled)')?.focus(); }, [popover]);
    const closePopover = () => { setPopover(null); trigger.current?.focus(); };
    const togglePopover = (name, event) => { trigger.current = event.currentTarget; setPopover((current) => current === name ? null : name); };
    const leave = async () => {
        if (leaving) return;
        setLeaving(true);
        try {
            await stopAll();
            await onLeave(() => room.disconnect());
        } finally {
            setLeaving(false);
        }
    };
    const togglePanel = (panel) => { closePopover(); onPanelChange(activePanel === panel ? null : panel); };
    const switchView = (next) => { onViewChange(next); closePopover(); };
    const keyDown = (event) => {
        if (event.key === 'Escape' && popover) { event.preventDefault(); event.stopPropagation(); closePopover(); }
        if (popover && ['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            const buttons = [...menuRef.current.querySelectorAll('button:not(:disabled)')];
            const index = buttons.indexOf(document.activeElement);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + buttons.length) % buttons.length;
            event.preventDefault(); buttons[next]?.focus();
        }
    };
    const menuButton = 'flex min-h-11 w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-bold text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-violet-400';

    return <div ref={ref} onKeyDown={keyDown} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setPopover(null); }} aria-label="Meeting controls" className={`meeting-control-bar relative mx-auto mt-4 flex w-full max-w-full flex-wrap items-center justify-center gap-2 rounded-2xl border border-white/[.08] bg-[#10131c]/95 p-2 shadow-2xl shadow-black/40 sm:gap-3 sm:p-2.5 ${activePanel ? 'meeting-control-bar--panel-open' : ''}`}>
        <Control className="meeting-control-chat" active={activePanel === 'chat'} onClick={() => togglePanel('chat')} aria-label="Open meeting chat" aria-expanded={activePanel === 'chat'}><Icon name="messages" className="h-5.5 w-5.5"/>Chat</Control>
        <Control className="meeting-control-people" active={activePanel === 'people'} onClick={() => togglePanel('people')} aria-label={`Open participants${waitingCount ? `, ${waitingCount} waiting` : ''}${raisedCount ? `, ${raisedCount} hands raised` : ''}`} aria-expanded={activePanel === 'people'}><span className="relative"><Icon name="users" className="h-5.5 w-5.5"/>{waitingCount > 0 && <span className="absolute -right-3 -top-2 rounded-full bg-amber-300 px-1 text-[9px] text-slate-950">{waitingCount > 99 ? '99+' : waitingCount}</span>}</span>People</Control>
        <Control className="meeting-control-utility" active={signals.localHandRaised} disabled={!available} onClick={() => signals.toggleHand().catch((error) => onMessage(error.message))} aria-label={signals.localHandRaised ? 'Lower hand' : 'Raise hand'} aria-pressed={signals.localHandRaised}><Icon name="hand" className="h-5.5 w-5.5"/>{signals.localHandRaised ? 'Lower' : 'Raise'}{raisedCount > 0 ? ` (${raisedCount})` : ''}</Control>
        <Control className="meeting-control-utility" active={popover === 'reactions'} disabled={!available} onClick={(event) => togglePopover('reactions', event)} aria-label="Open meeting reactions" aria-expanded={popover === 'reactions'}><Icon name="smile" className="h-5.5 w-5.5"/>React</Control>
        <div className="meeting-device-control flex items-center rounded-xl border border-white/[.12] bg-white/[.1] min-w-[140px]"><TrackToggle source={Track.Source.Camera} showIcon={false} disabled={!available || !mediaReady} onClick={() => writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: !isCameraEnabled})} onChange={(enabled, isUserInitiated) => { if (isUserInitiated) writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: enabled}); }} onDeviceError={() => { writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: false}); onMessage('Camera could not be enabled. Check browser permissions or device availability.'); }} className="meeting-device-toggle inline-flex min-h-[68px] min-w-[70px] flex-col items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/[.08] focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={isCameraEnabled ? 'Turn camera off' : 'Turn camera on'} aria-pressed={isCameraEnabled}><Icon name={isCameraEnabled ? 'video' : 'video-off'} className="h-5.5 w-5.5"/>{isCameraEnabled ? 'Camera' : 'Camera off'}</TrackToggle><div className="hidden sm:block"><DeviceSelect kind="videoinput" label="Camera" onMessage={onMessage}/></div></div>
        <div className="meeting-device-control flex items-center rounded-xl border border-white/[.12] bg-white/[.1] min-w-[140px]"><TrackToggle source={Track.Source.Microphone} showIcon={false} disabled={!available || !mediaReady} onClick={() => writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: !isMicrophoneEnabled})} onChange={(enabled, isUserInitiated) => { if (isUserInitiated) writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: enabled}); }} onDeviceError={() => { writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: false}); onMessage('Microphone could not be enabled. Check browser permissions or device availability.'); }} className="meeting-device-toggle inline-flex min-h-[68px] min-w-[70px] flex-col items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/[.08] focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={isMicrophoneEnabled ? 'Mute microphone' : 'Unmute microphone'} aria-pressed={isMicrophoneEnabled}><Icon name={isMicrophoneEnabled ? 'mic' : 'mic-off'} className="h-5.5 w-5.5"/>{isMicrophoneEnabled ? 'Mic' : 'Muted'}</TrackToggle><div className="hidden sm:block"><DeviceSelect kind="audioinput" label="Microphone" onMessage={onMessage}/></div></div>
        {meeting.can_screen_share && <Control active={isScreenShareEnabled} wide={resumeScreenShare && !isScreenShareEnabled} disabled={!available || share.pending} onClick={(event) => { if (isScreenShareEnabled) writeMeetingMediaIntent(mediaIntentKey, {wasScreenSharing: false}); share.buttonProps.onClick(event); }} aria-label={resumeScreenShare && !isScreenShareEnabled ? 'Resume screen sharing' : isScreenShareEnabled ? 'Stop sharing screen' : 'Share screen'} aria-pressed={isScreenShareEnabled}><Icon name="screen" className="h-5.5 w-5.5"/>{resumeScreenShare && !isScreenShareEnabled ? 'Resume screen sharing' : isScreenShareEnabled ? 'Stop share' : 'Share'}</Control>}
        <div className="meeting-control-secondary hidden lg:block"><Control active={popover === 'view'} onClick={(event) => togglePopover('view', event)} aria-label="Open view options" aria-expanded={popover === 'view'}><Icon name="eye" className="h-5.5 w-5.5"/>View</Control></div>
        {canHost && <div className="meeting-control-secondary hidden lg:block"><Control active={activePanel === 'host'} onClick={() => togglePanel('host')} aria-label="Open host controls" aria-expanded={activePanel === 'host'}><Icon name="settings" className="h-5.5 w-5.5"/>Host</Control></div>}
        <Control className="meeting-control-more shrink-0" active={popover === 'more'} onClick={(event) => togglePopover('more', event)} aria-label="More meeting controls" aria-expanded={popover === 'more'}><Icon name="more" className="h-5.5 w-5.5"/>More</Control>
        <Control className="meeting-control-leave shrink-0" danger disabled={connection === 'disconnected' || leaving} onClick={leave} aria-label="Leave meeting"><Icon name="logout" className="h-5.5 w-5.5"/>{leaving ? 'Leaving' : 'Leave'}</Control>
        {popover && <div ref={menuRef} className="absolute inset-x-0 bottom-[calc(100%+.75rem)] z-30 mx-auto max-h-[60dvh] max-w-sm overflow-y-auto rounded-2xl border border-white/10 bg-[#171a23] p-2 shadow-2xl" role="group" aria-label={popover === 'view' ? 'View options' : popover === 'more' ? 'More meeting controls' : 'Meeting reactions'}>
            {popover === 'reactions' && <div className="flex flex-wrap justify-center gap-1">{['👍', '❤️', '👏', '😂', '😮'].map((reaction) => <button key={reaction} type="button" disabled={!available} onClick={() => { signals.sendReaction(reaction).catch((error) => onMessage(error.message)); closePopover(); }} className="rounded-xl p-2 text-xl transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={`Send ${reaction} reaction`}>{reaction}</button>)}</div>}
            {popover === 'view' && <><p className="px-3 py-2 text-xs text-slate-300">View changes apply only to you.</p>{meetingViews.map((option) => <button key={option.id} type="button" onClick={() => switchView(option.id)} aria-label={`Switch to ${option.id === 'speaker' ? 'speaker' : option.id === 'screen' ? 'screen-share focus' : 'gallery'} view`} aria-pressed={view === option.id} className={`${menuButton} ${view === option.id ? 'bg-violet-600/40' : ''}`}><Icon name={option.icon} className="h-4 w-4"/><span>{option.label}<span className="block text-xs font-normal text-slate-300">{option.description}</span></span></button>)}</>}
            {popover === 'more' && <>
                <button type="button" onClick={() => togglePanel('chat')} className={`${menuButton} meeting-more-narrow`} aria-label="Open meeting chat"><Icon name="messages" className="h-4 w-4"/>Chat</button>
                <button type="button" onClick={() => togglePanel('people')} className={`${menuButton} meeting-more-narrow`} aria-label="Open participants"><Icon name="users" className="h-4 w-4"/>People</button>
                <button type="button" disabled={!available} onClick={() => { signals.toggleHand().catch((error) => onMessage(error.message)); closePopover(); }} className={`${menuButton} meeting-more-utility`} aria-label={signals.localHandRaised ? 'Lower hand' : 'Raise hand'}><Icon name="hand" className="h-4 w-4"/>{signals.localHandRaised ? 'Lower hand' : 'Raise hand'}</button>
                <button type="button" disabled={!available} onClick={() => setPopover('reactions')} className={`${menuButton} meeting-more-utility`} aria-label="Open meeting reactions"><Icon name="smile" className="h-4 w-4"/>Reactions</button>
                <button type="button" onClick={() => togglePanel('info')} className={menuButton} aria-label="Open meeting info"><Icon name="calendar" className="h-4 w-4"/>Meeting info</button>
                <button type="button" onClick={() => setPopover('view')} className={menuButton} aria-label="Open view options"><Icon name="eye" className="h-4 w-4"/>View options</button>
                {canHost && <button type="button" onClick={() => togglePanel('host')} className={menuButton} aria-label="Open host controls"><Icon name="settings" className="h-4 w-4"/>Host controls</button>}
                <button type="button" onClick={() => togglePanel('devices')} className={menuButton}><Icon name="settings" className="h-4 w-4"/>Device settings</button>
                {hasMeetingLink && <button type="button" onClick={onCopyLink} className={menuButton} aria-label="Copy meeting link"><Icon name="clipboard" className="h-4 w-4"/>Copy meeting link</button>}
                {copied && <p role="status" className="px-3 py-2 text-xs text-slate-200">{copied}</p>}
            </>}
        </div>}
    </div>;
}

import React, {useCallback, useEffect, useId, useRef, useState} from 'react';
import {TrackToggle, useConnectionState, useLocalParticipant, useRoomContext, useTrackToggle} from '@livekit/components-react';
import {Track} from 'livekit-client';
import Icon from '../../UI/Icon';
import {MeetingSidePanel} from './MeetingSidePanel';
import {meetingViews} from './meetingView';
import {readMeetingMediaIntent, screenShareIntentChange, writeMeetingMediaIntent} from './meetingMediaIntent';
import {useTranslation} from '../../../i18n/LocaleProvider';
import {hasNotice, noticeKey, noticeRaw, renderNotice} from '../../../i18n/notice';
import useMeetingFullscreen from '../../../Hooks/Meetings/useMeetingFullscreen';
import {screenShareButtonState, screenShareLabelKey, shouldStartScreenCapture} from './screenShareApproval';
import {MeetingRecordingDialogs, RecordingBadge} from './MeetingRecordingControls';

function DeviceSelect({kind, labelKey, onMessage, expanded = false}) {
    const {t} = useTranslation();
    const label = t(labelKey);
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
        // The device name is a nested translation, so the param resolves lazily and
        // the notice follows the locale even while it is on screen.
        const device = (translate) => translate(labelKey);
        try { await room.switchActiveDevice(kind, event.target.value, true); setOpen(false); onMessage(noticeKey('meetingRoom.controlCenter.deviceUpdated', {device})); } catch { onMessage(noticeKey('meetingRoom.controlCenter.deviceSwitchFailed', {device})); }
    };
    return <div className="relative" ref={ref} onKeyDown={(event) => { if (event.key === 'Escape' && open) { event.stopPropagation(); setOpen(false); ref.current?.querySelector('button')?.focus(); } }}>{!expanded && <button type="button" onClick={() => setOpen((value) => !value)} className="rounded-lg p-1.5 text-slate-300 transition hover:bg-white/10 hover:text-white focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={t('meetingRoom.controlCenter.chooseDevice', {device: label})} aria-expanded={open}><Icon name="chevron-down" className="h-3.5 w-3.5"/></button>}{(open || expanded) && <div className={expanded ? "rounded-2xl bg-slate-800 p-3" : "fixed inset-x-4 bottom-24 z-50 rounded-2xl border border-white/10 bg-[#171a23] p-3 shadow-2xl sm:absolute sm:inset-x-auto sm:bottom-[calc(100%+.6rem)] sm:left-0 sm:w-60"}><p className="mb-2 text-xs font-bold text-white">{label}</p><label className="sr-only" htmlFor={id}>{t('meetingRoom.controlCenter.chooseDeviceField', {device: label})}</label><select id={id} defaultValue={room.getActiveDevice(kind) || ''} onChange={choose} className="w-full rounded-xl border border-white/15 bg-white/10 px-3 py-2 text-sm text-white outline-none focus:ring-2 focus:ring-violet-400">{devices.map((device, index) => <option className="text-slate-900" key={device.deviceId} value={device.deviceId}>{device.label || t('meetingRoom.controlCenter.deviceNumber', {device: label, index: index + 1})}</option>)}</select></div>}</div>;
}

function Control({active, danger = false, wide = false, leave = false, className = '', children, ...props}) {
    const widthClass = leave ? 'min-w-[100px] flex-shrink-0' : wide ? 'min-w-[140px]' : 'min-w-[92px]';
    return <button type="button" {...props} className={`inline-flex min-h-[68px] ${widthClass} flex-col items-center justify-center gap-1.5 rounded-xl border px-3 py-2 text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:cursor-not-allowed disabled:opacity-45 ${danger ? 'border-rose-400/35 bg-rose-600 text-white hover:bg-rose-700 focus:ring-rose-300' : active ? 'border-violet-300/60 bg-violet-600 text-white shadow-[0_0_0_2px_rgba(139,92,246,.4)]' : 'border-white/[.12] bg-white/[.1] text-white hover:border-white/20 hover:bg-white/[.18]'} ${className}`}>
        {children}
    </button>;
}

export function MeetingDeviceSettings({onClose, onMessage}) {
    const {t} = useTranslation();

    return <MeetingSidePanel title={t('meetingRoom.controlCenter.deviceSettings')} icon="settings" onClose={onClose}><div className="space-y-4 overflow-y-auto p-4"><p className="text-sm text-slate-600">{t('meetingRoom.controlCenter.deviceSettingsHint')}</p><DeviceSelect expanded kind="videoinput" labelKey="meetingRoom.controlCenter.cameraLabel" onMessage={onMessage}/><DeviceSelect expanded kind="audioinput" labelKey="meetingRoom.controlCenter.microphoneLabel" onMessage={onMessage}/></div></MeetingSidePanel>;
}

export default function MeetingControlCenter({meeting, activePanel, onPanelChange, signals, waitingCount, screenShareRequestCount = 0, screenShareApproval, raisedCount, view, onViewChange, onCopyLink, copied, hasMeetingLink, mediaIntent, mediaIntentKey, mediaReady, onLeave, onMessage, isFullscreen, exitFullscreen, recording, onOpenBuddy, aiControl}) {
    const {t} = useTranslation();
    const connection = useConnectionState();
    const room = useRoomContext();
    const {localParticipant, isCameraEnabled, isMicrophoneEnabled, isScreenShareEnabled} = useLocalParticipant();
    const {enterFullscreen, fullscreenSupported} = useMeetingFullscreen();
    const [resumeScreenShare, setResumeScreenShare] = useState(() => readMeetingMediaIntent(mediaIntentKey)?.wasScreenSharing ?? mediaIntent?.wasScreenSharing === true);
    const screenShareWasEnabled = useRef(isScreenShareEnabled);
    // LiveKit's interaction flag can be false by the time the asynchronous
    // display-capture publication becomes observable. This one-shot latch is
    // armed only by the approved Start sharing click below.
    const pendingApprovedShareStartRef = useRef(false);
    const leavingPage = useRef(false);
    const screenShareChanged = useCallback((enabled, isUserInitiated) => {
        const wasEnabled = screenShareWasEnabled.current;
        screenShareWasEnabled.current = enabled;
        const intent = screenShareIntentChange({enabled, isUserInitiated, wasEnabled, isUnmounting: leavingPage.current});
        if (intent) writeMeetingMediaIntent(mediaIntentKey, intent);
        // The application owns the explicit gesture truth; LiveKit owns the
        // publication truth. Both are needed, so approval, mount and reconnect
        // observations can never reconcile by themselves. Fire and forget: a
        // failure must never stop the local share.
        if (enabled && meeting.requires_screen_share_approval && pendingApprovedShareStartRef.current) {
            pendingApprovedShareStartRef.current = false;
            screenShareApproval?.reconcile();
        }
        if (wasEnabled && !enabled && !leavingPage.current && meeting.requires_screen_share_approval) screenShareApproval?.complete();
    }, [mediaIntentKey, meeting.requires_screen_share_approval, screenShareApproval]);
    const share = useTrackToggle({source: Track.Source.ScreenShare, captureOptions: {contentHint: 'text'}, onChange: screenShareChanged, onDeviceError: () => { pendingApprovedShareStartRef.current = false; onMessage(noticeKey('meetingRoom.controlCenter.shareUnavailable')); if (meeting.requires_screen_share_approval) screenShareApproval?.complete(); }});
    const [popover, setPopover] = useState(null);
    const [leaving, setLeaving] = useState(false);
    const leavingRef = useRef(false);
    const available = connection === 'connected' && meeting.status === 'active';
    const canHost = meeting.can_end || meeting.can_manage_participants || meeting.can_manage_join_requests || meeting.can_manage_screen_share_requests;
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
            pendingApprovedShareStartRef.current = false;
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
        if (leavingRef.current) return;
        leavingRef.current = true;
        setLeaving(true);
        try {
            await stopAll();
            await onLeave(() => room.disconnect());
        } finally {
            leavingRef.current = false;
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
    const peopleLabel = [t('meetingRoom.controlCenter.openPeople'), waitingCount ? t('meetingRoom.controlCenter.openPeopleWaiting', {count: waitingCount}) : '', raisedCount ? t('meetingRoom.controlCenter.openPeopleRaised', {count: raisedCount}) : ''].join('');
    const shareState = screenShareButtonState({canShareDirectly: meeting.can_screen_share, requiresApproval: meeting.requires_screen_share_approval, isSharing: isScreenShareEnabled, requestStatus: screenShareApproval?.status, requesting: screenShareApproval?.requesting});
    const shareLabelKey = meeting.can_screen_share && resumeScreenShare && !isScreenShareEnabled ? 'meetingRoom.controlCenter.resumeScreenShare' : screenShareLabelKey(shareState);
    const shareLabel = t(shareLabelKey);
    const screenShareDisabled = !available || share.pending || ['requesting', 'pending'].includes(shareState);
    const toggleScreenShare = (event) => {
        if (shareState === 'request') return screenShareApproval?.request();
        if (!shouldStartScreenCapture(shareState)) return;
        if (isScreenShareEnabled) writeMeetingMediaIntent(mediaIntentKey, {wasScreenSharing: false});
        if (meeting.requires_screen_share_approval && shareState === 'approved') pendingApprovedShareStartRef.current = true;
        share.buttonProps.onClick(event);
    };

    return <>
    {recording?.state && <div className="mx-auto mb-2 flex w-full max-w-full justify-center"><RecordingBadge recording={recording.state} clock={recording.clock}/></div>}
    <div ref={ref} onKeyDown={keyDown} onBlur={(event) => { if (!event.currentTarget.contains(event.relatedTarget)) setPopover(null); }} aria-label={t('meetingRoom.controlCenter.label')} className={`meeting-control-bar relative mx-auto mt-4 flex w-full max-w-full shrink-0 flex-nowrap items-center justify-center gap-2 rounded-2xl border border-white/[.08] bg-[#10131c]/95 p-2 shadow-2xl shadow-black/40 sm:gap-3 sm:p-2.5 ${activePanel ? 'meeting-control-bar--panel-open' : ''}`}>
        <Control className="meeting-control-chat" active={activePanel === 'chat'} onClick={() => togglePanel('chat')} aria-label={t('meetingRoom.controlCenter.openChat')} aria-expanded={activePanel === 'chat'}><Icon name="messages" className="h-5.5 w-5.5"/>{t('meetingRoom.controlCenter.chat')}</Control>
        <Control className="meeting-control-people" active={activePanel === 'people'} onClick={() => togglePanel('people')} aria-label={peopleLabel} aria-expanded={activePanel === 'people'}><span className="relative"><Icon name="users" className="h-5.5 w-5.5"/>{waitingCount > 0 && <span className="absolute -right-3 -top-2 rounded-full bg-amber-300 px-1 text-[9px] text-slate-950">{waitingCount > 99 ? '99+' : waitingCount}</span>}</span>{t('meetingRoom.controlCenter.people')}</Control>
        <Control className="meeting-control-utility" active={signals.localHandRaised} disabled={!available} onClick={() => signals.toggleHand().catch((error) => onMessage(noticeRaw(error.message)))} aria-label={t(signals.localHandRaised ? 'meetingRoom.controlCenter.lowerHand' : 'meetingRoom.controlCenter.raiseHand')} aria-pressed={signals.localHandRaised}><Icon name="hand" className="h-5.5 w-5.5"/>{t(signals.localHandRaised ? 'meetingRoom.controlCenter.lower' : 'meetingRoom.controlCenter.raise')}{raisedCount > 0 ? ` (${raisedCount})` : ''}</Control>
        <Control className="meeting-control-utility" active={popover === 'reactions'} disabled={!available} onClick={(event) => togglePopover('reactions', event)} aria-label={t('meetingRoom.controlCenter.openReactions')} aria-expanded={popover === 'reactions'}><Icon name="smile" className="h-5.5 w-5.5"/>{t('meetingRoom.controlCenter.react')}</Control>
        <div className="meeting-device-control flex items-center rounded-xl border border-white/[.12] bg-white/[.1] min-w-[140px]"><TrackToggle source={Track.Source.Camera} showIcon={false} disabled={!available || !mediaReady} onClick={() => writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: !isCameraEnabled})} onChange={(enabled, isUserInitiated) => { if (isUserInitiated) writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: enabled}); }} onDeviceError={() => { writeMeetingMediaIntent(mediaIntentKey, {cameraEnabled: false}); onMessage(noticeKey('meetingRoom.controlCenter.cameraEnableFailed')); }} className="meeting-device-toggle inline-flex min-h-[68px] min-w-[70px] flex-col items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/[.08] focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={t(isCameraEnabled ? 'meetingRoom.controlCenter.turnCameraOff' : 'meetingRoom.controlCenter.turnCameraOn')} aria-pressed={isCameraEnabled}><Icon name={isCameraEnabled ? 'video' : 'video-off'} className="h-5.5 w-5.5"/>{t(isCameraEnabled ? 'meetingRoom.controlCenter.camera' : 'meetingRoom.controlCenter.cameraOff')}</TrackToggle><div className="hidden sm:block"><DeviceSelect kind="videoinput" labelKey="meetingRoom.controlCenter.cameraLabel" onMessage={onMessage}/></div></div>
        <div className="meeting-device-control flex items-center rounded-xl border border-white/[.12] bg-white/[.1] min-w-[140px]"><TrackToggle source={Track.Source.Microphone} showIcon={false} disabled={!available || !mediaReady} onClick={() => writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: !isMicrophoneEnabled})} onChange={(enabled, isUserInitiated) => { if (isUserInitiated) writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: enabled}); }} onDeviceError={() => { writeMeetingMediaIntent(mediaIntentKey, {microphoneEnabled: false}); onMessage(noticeKey('meetingRoom.controlCenter.micEnableFailed')); }} className="meeting-device-toggle inline-flex min-h-[68px] min-w-[70px] flex-col items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold text-white transition hover:bg-white/[.08] focus:outline-none focus:ring-2 focus:ring-violet-400 disabled:opacity-45" aria-label={t(isMicrophoneEnabled ? 'meetingRoom.controlCenter.muteMicrophone' : 'meetingRoom.controlCenter.unmuteMicrophone')} aria-pressed={isMicrophoneEnabled}><Icon name={isMicrophoneEnabled ? 'mic' : 'mic-off'} className="h-5.5 w-5.5"/>{t(isMicrophoneEnabled ? 'meetingRoom.controlCenter.mic' : 'meetingRoom.controlCenter.muted')}</TrackToggle><div className="hidden sm:block"><DeviceSelect kind="audioinput" labelKey="meetingRoom.controlCenter.microphoneLabel" onMessage={onMessage}/></div></div>
        {(meeting.can_screen_share || meeting.requires_screen_share_approval) && <Control active={isScreenShareEnabled || shareState === 'approved'} wide={resumeScreenShare || ['requesting', 'pending', 'approved'].includes(shareState)} disabled={screenShareDisabled} onClick={toggleScreenShare} aria-label={shareLabel} aria-pressed={isScreenShareEnabled}><Icon name="screen" className="h-5.5 w-5.5"/>{shareLabel}</Control>}
        {aiControl && <Control className="meeting-control-ai shrink-0" active={aiControl.active} disabled={!available} onClick={aiControl.onClick} aria-label={aiControl.ariaLabel} aria-expanded={aiControl.active}><Icon name="sparkles" className="h-5.5 w-5.5"/>{aiControl.label}</Control>}
        <div className="meeting-control-secondary hidden lg:block"><Control active={popover === 'view'} onClick={(event) => togglePopover('view', event)} aria-label={t('meetingRoom.controlCenter.openView')} aria-expanded={popover === 'view'}><Icon name="eye" className="h-5.5 w-5.5"/>{t('meetingRoom.controlCenter.view')}</Control></div>
        {fullscreenSupported && <Control className="meeting-control-utility" active={isFullscreen} onClick={() => isFullscreen ? exitFullscreen() : enterFullscreen()} aria-label={t(isFullscreen ? 'meetingRoom.stage.fullscreen.exit' : 'meetingRoom.stage.fullscreen.enter')} aria-pressed={isFullscreen}><Icon name={isFullscreen ? 'minimize' : 'maximize'} className="h-5.5 w-5.5"/>{t(isFullscreen ? 'meetingRoom.stage.fullscreen.exit' : 'meetingRoom.stage.fullscreen.enter')}</Control>}
        {canHost && <div className="meeting-control-secondary hidden lg:block"><Control active={activePanel === 'host'} onClick={() => togglePanel('host')} aria-label={t('meetingRoom.controlCenter.openHostControls')} aria-expanded={activePanel === 'host'}><span className="relative"><Icon name="settings" className="h-5.5 w-5.5"/>{screenShareRequestCount > 0 && <span className="absolute -right-3 -top-2 rounded-full bg-amber-300 px-1 text-[9px] text-slate-950">{screenShareRequestCount > 99 ? '99+' : screenShareRequestCount}</span>}</span>{t('meetingRoom.controlCenter.host')}</Control></div>}
        <Control className="meeting-control-more shrink-0" active={popover === 'more'} onClick={(event) => togglePopover('more', event)} aria-label={t('meetingRoom.controlCenter.moreControls')} aria-expanded={popover === 'more'}><Icon name="more" className="h-5.5 w-5.5"/>{t('meetingRoom.controlCenter.more')}</Control>
        {recording?.showStop && <Control className="meeting-control-stop-recording shrink-0" danger disabled={recording.stopping} onClick={recording.openStop} aria-label={t('meetingRoom.recording.stopRecording')}><span className="meeting-recording-dot" aria-hidden="true"/>{t(recording.stopping ? 'meetingRoom.recording.stopping' : 'meetingRoom.recording.stop')}</Control>}
        <Control className="meeting-control-leave shrink-0" danger disabled={leaving} onClick={leave} aria-label={t('meetingRoom.controlCenter.leaveMeeting')}><Icon name="logout" className="h-5.5 w-5.5"/>{t(leaving ? 'meetingRoom.controlCenter.leaving' : 'meetingRoom.controlCenter.leave')}</Control>
        {popover && <div ref={menuRef} className="absolute inset-x-0 bottom-[calc(100%+.75rem)] z-30 mx-auto max-h-[60dvh] max-w-sm overflow-y-auto rounded-2xl border border-white/10 bg-[#171a23] p-2 shadow-2xl" role="group" aria-label={t(popover === 'view' ? 'meetingRoom.controlCenter.viewOptions' : popover === 'more' ? 'meetingRoom.controlCenter.moreControls' : 'meetingRoom.controlCenter.openReactions')}>
            {popover === 'reactions' && <div className="flex flex-wrap justify-center gap-1">{['👍', '❤️', '👏', '😂', '😮'].map((reaction) => <button key={reaction} type="button" disabled={!available} onClick={() => { signals.sendReaction(reaction).catch((error) => onMessage(noticeRaw(error.message))); closePopover(); }} className="rounded-xl p-2 text-xl transition hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-violet-400" aria-label={t('meetingRoom.controlCenter.sendReaction', {reaction})}>{reaction}</button>)}</div>}
            {popover === 'view' && <><p className="px-3 py-2 text-xs text-slate-300">{t('meetingRoom.controlCenter.viewLocalOnly')}</p>{meetingViews.map((option) => <button key={option.id} type="button" onClick={() => switchView(option.id)} aria-label={t('meetingRoom.controlCenter.switchToView', {view: t(option.labelKey)})} aria-pressed={view === option.id} className={`${menuButton} ${view === option.id ? 'bg-violet-600/40' : ''}`}><Icon name={option.icon} className="h-4 w-4"/><span>{t(option.labelKey)}<span className="block text-xs font-normal text-slate-300">{t(option.descriptionKey)}</span></span></button>)}</>}
            {popover === 'more' && <>
                <button type="button" onClick={() => togglePanel('chat')} className={`${menuButton} meeting-more-narrow`} aria-label={t('meetingRoom.controlCenter.openChat')}><Icon name="messages" className="h-4 w-4"/>{t('meetingRoom.controlCenter.chat')}</button>
                <button type="button" onClick={() => togglePanel('people')} className={`${menuButton} meeting-more-narrow`} aria-label={t('meetingRoom.controlCenter.openPeople')}><Icon name="users" className="h-4 w-4"/>{t('meetingRoom.controlCenter.people')}</button>
                <button type="button" disabled={!available} onClick={() => { signals.toggleHand().catch((error) => onMessage(noticeRaw(error.message))); closePopover(); }} className={`${menuButton} meeting-more-utility`} aria-label={t(signals.localHandRaised ? 'meetingRoom.controlCenter.lowerHand' : 'meetingRoom.controlCenter.raiseHand')}><Icon name="hand" className="h-4 w-4"/>{t(signals.localHandRaised ? 'meetingRoom.controlCenter.lowerHand' : 'meetingRoom.controlCenter.raiseHand')}</button>
                <button type="button" disabled={!available} onClick={() => setPopover('reactions')} className={`${menuButton} meeting-more-utility`} aria-label={t('meetingRoom.controlCenter.openReactions')}><Icon name="smile" className="h-4 w-4"/>{t('meetingRoom.controlCenter.reactions')}</button>
                <button type="button" onClick={() => togglePanel('info')} className={menuButton} aria-label={t('meetingRoom.controlCenter.openMeetingInfo')}><Icon name="calendar" className="h-4 w-4"/>{t('meetingRoom.controlCenter.meetingInfo')}</button>
                <button type="button" onClick={() => setPopover('view')} className={menuButton} aria-label={t('meetingRoom.controlCenter.openView')}><Icon name="eye" className="h-4 w-4"/>{t('meetingRoom.controlCenter.viewOptions')}</button>
                {canHost && <button type="button" onClick={() => togglePanel('host')} className={menuButton} aria-label={t('meetingRoom.controlCenter.openHostControls')}><Icon name="settings" className="h-4 w-4"/>{t('meetingRoom.controlCenter.hostControls')}</button>}
                {/* Recording is offered from More, and only when the server says this
                    viewer may record, so a student never sees the entry at all. */}
                {recording?.canStart && <button type="button" disabled={!available} onClick={() => { closePopover(); recording.openStart(); }} className={menuButton} aria-label={t('meetingRoom.recording.startRecording')} data-testid="meeting-recording-start-menu"><Icon name="record" className="h-4 w-4"/>{t('meetingRoom.recording.start')}</button>}
                <button type="button" onClick={() => togglePanel('devices')} className={menuButton}><Icon name="settings" className="h-4 w-4"/>{t('meetingRoom.controlCenter.deviceSettings')}</button>
                {fullscreenSupported && <button type="button" onClick={() => { isFullscreen ? exitFullscreen() : enterFullscreen(); closePopover(); }} className={`${menuButton} meeting-more-utility`} aria-label={t(isFullscreen ? 'meetingRoom.stage.fullscreen.exit' : 'meetingRoom.stage.fullscreen.enter')}><Icon name={isFullscreen ? 'minimize' : 'maximize'} className="h-4 w-4"/>{t(isFullscreen ? 'meetingRoom.stage.fullscreen.exit' : 'meetingRoom.stage.fullscreen.enter')}</button>}
                {hasMeetingLink && <button type="button" onClick={onCopyLink} className={menuButton} aria-label={t('meetingRoom.controlCenter.copyMeetingLink')}><Icon name="clipboard" className="h-4 w-4"/>{t('meetingRoom.controlCenter.copyMeetingLink')}</button>}
                {hasNotice(copied) && <p role="status" className="px-3 py-2 text-xs text-slate-200">{renderNotice(copied, t)}</p>}
            </>}
        </div>}
    </div>
    <MeetingRecordingDialogs recording={recording}/>
    </>;
}

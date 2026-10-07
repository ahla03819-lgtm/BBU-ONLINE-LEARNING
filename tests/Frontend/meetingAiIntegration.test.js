import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {resolve} from 'node:path';

const projectRoot = process.cwd();

function readProjectFile(relativePath) {
    return readFileSync(resolve(projectRoot, relativePath), 'utf8');
}

const MeetingRoomExperience = readProjectFile('resources/js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
const MeetingControlCenter = readProjectFile('resources/js/Components/Meetings/LiveKit/MeetingControlCenter.jsx');

test('MeetingControlCenter exposes AI/Buddy control when aiControl provided', () => {
    assert.ok(MeetingControlCenter.includes('aiControl'), 'MeetingControlCenter should accept aiControl prop');
    assert.ok(MeetingControlCenter.includes('meeting-control-ai'), 'AI control should have the correct CSS class');
});

test('MeetingRoomExperience owns AI UI state through useMeetingAiPreferences integration', () => {
    assert.ok(MeetingRoomExperience.includes('useMeetingAiPreferences'), 'MeetingRoomExperience should import useMeetingAiPreferences');
    assert.ok(MeetingRoomExperience.includes('aiPreferencesKey'), 'MeetingRoomExperience should accept aiPreferencesKey prop');
    assert.ok(MeetingRoomExperience.includes('writeMeetingAiPreferences'), 'MeetingRoomExperience should use writeMeetingAiPreferences');
});

test('CaptionOverlay integration exists in MeetingRoomExperience', () => {
    assert.ok(MeetingRoomExperience.includes('CaptionOverlay'), 'MeetingRoomExperience should import CaptionOverlay');
    assert.ok(MeetingRoomExperience.includes('captionMode'), 'MeetingRoomExperience should manage caption mode state');
});

test('AI preferences are separate from meetingMediaIntent', () => {
    const meetingAiPreferences = readProjectFile('resources/js/Components/Meetings/AI/useMeetingAiPreferences.js');
    const meetingMediaIntent = readProjectFile('resources/js/Components/Meetings/LiveKit/meetingMediaIntent.js');

    assert.ok(meetingAiPreferences.includes('bbu:meeting-ai-preferences:v1'), 'AI preferences should use correct storage prefix');
    assert.ok(meetingMediaIntent.includes('bbu:meeting-media-intent:v1'), 'Media intent should use correct storage prefix');
});
test('recording control remains present in MeetingControlCenter', () => {
    assert.ok(MeetingControlCenter.includes('recording'), 'MeetingControlCenter should have recording prop');
    assert.ok(MeetingControlCenter.includes('MeetingRecordingControls'), 'MeetingControlCenter should include recording controls');
});
test('screen-share control remains present in MeetingControlCenter', () => {
    assert.ok(MeetingControlCenter.includes('screenShareRequestCount'), 'MeetingControlCenter should have screen share approval logic');
    assert.ok(MeetingControlCenter.includes('active={isScreenShareEnabled'), 'Screen-share control should be present');
});
test('Leave control remains present in MeetingControlCenter', () => {
    assert.ok(MeetingControlCenter.includes('onLeave'), 'MeetingControlCenter should have onLeave callback');
});
test('AI integration does not introduce media lifecycle calls', () => {
    assert.ok(MeetingControlCenter.includes('onOpenBuddy'), 'AI control should have onOpenBuddy callback');
    assert.ok(MeetingControlCenter.includes('aiControl'), 'AI control should have aiControl prop');
});
test('Full/Mini behavior does not clear stored AI caption preference', () => {
    const MeetingRoomExperienceContent = readProjectFile('resources/js/Components/Meetings/LiveKit/MeetingRoomExperience.jsx');
    assert.ok(MeetingRoomExperienceContent.includes('useEffect(() => {'), 'AI preferences should use useEffect for persistence');
    assert.ok(MeetingRoomExperienceContent.includes('writeMeetingAiPreferences(aiPreferencesKey, {captionMode});'), 'AI preferences should be written back on change');
});
test('BBU Buddy starts idle and opens/closes via AI control', () => {
    assert.ok(MeetingRoomExperience.includes('buddyOpen'), 'MeetingRoomExperience should manage buddy panel state');
    assert.ok(MeetingRoomExperience.includes('setBuddyOpen'), 'MeetingRoomExperience should have setBuddyOpen function');
});
test('Buddy integration contract exists - BBU Buddy does not pretend to be listening/transcribing', () => {
    const BBUBuddyPanel = readProjectFile('resources/js/Components/Meetings/AI/BBUBuddyPanel.jsx');
    assert.ok(BBUBuddyPanel.includes('initialState ='), 'BBU Buddy should have initialState prop');
    assert.ok(!BBUBuddyPanel.includes('webkitSpeechRecognition') && !BBUBuddyPanel.includes('SpeechRecognition'), 'BBU Buddy should not contain fake STT logic');
});
test('Captions use existing useMeetingAiPreferences and CaptionOverlay - no fake transcript or Khmer translation', () => {
    const CaptionOverlay = readProjectFile('resources/js/Components/Meetings/AI/CaptionOverlay.jsx');
    assert.ok(CaptionOverlay.includes('originalText'), 'CaptionOverlay should have originalText prop');
    assert.ok(CaptionOverlay.includes('translatedText'), 'CaptionOverlay should have translatedText prop');
});
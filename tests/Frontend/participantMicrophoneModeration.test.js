import assert from 'node:assert/strict';
import test from 'node:test';
import {
    canShowMuteParticipant,
    canStartMuteParticipant,
    muteResultTranslationKey,
    requestParticipantMute,
} from '../../resources/js/Components/Meetings/LiveKit/participantMicrophoneModeration.js';

const remoteWithMic = {isLocal: false, isMicrophoneEnabled: true};
const eligibleRecord = {reference: 'participant-public-id', can_mute: true};

test('mute is visible only to an authorized moderator for an eligible remote participant', () => {
    assert.equal(canShowMuteParticipant({canManageParticipants: true, participant: remoteWithMic, record: eligibleRecord}), true);
    assert.equal(canShowMuteParticipant({canManageParticipants: false, participant: remoteWithMic, record: eligibleRecord}), false);
    assert.equal(canShowMuteParticipant({canManageParticipants: true, participant: remoteWithMic, record: {...eligibleRecord, can_mute: false}}), false);
});

test('mute is hidden for the local participant', () => {
    assert.equal(canShowMuteParticipant({canManageParticipants: true, participant: {...remoteWithMic, isLocal: true}, record: eligibleRecord}), false);
});

test('mute is hidden when LiveKit reports the participant microphone muted', () => {
    assert.equal(canShowMuteParticipant({canManageParticipants: true, participant: {...remoteWithMic, isMicrophoneEnabled: false}, record: eligibleRecord}), false);
});

test('mute request calls only the scoped public-reference endpoint', async () => {
    const calls = [];
    const response = {ok: true};
    const result = await requestParticipantMute({
        fetcher: async (...args) => { calls.push(args); return response; },
        base: '/collaboration/classes/7/meetings/meeting-public-id',
        reference: 'participant/public id',
        csrf: 'csrf-token',
    });

    assert.equal(result, response);
    assert.deepEqual(calls, [[
        '/collaboration/classes/7/meetings/meeting-public-id/participants/participant%2Fpublic%20id/mute',
        {method: 'PATCH', headers: {'X-CSRF-TOKEN': 'csrf-token', Accept: 'application/json', 'Content-Type': 'application/json'}},
    ]]);
});

test('busy state prevents a duplicate mute click while preserving participant-specific feedback', () => {
    const busy = `mute:${eligibleRecord.reference}`;
    assert.equal(canStartMuteParticipant({available: true, canManageParticipants: true, record: eligibleRecord, busy: null}), true);
    assert.equal(canStartMuteParticipant({available: true, canManageParticipants: true, record: eligibleRecord, busy}), false);
    assert.equal(busy, 'mute:participant-public-id');
    assert.equal(busy.startsWith('mute:'), true);
    assert.equal(busy.slice(5), eligibleRecord.reference);
});

test('LiveKit microphone state drives mute eligibility without a local fake state', () => {
    const participant = {...remoteWithMic};
    assert.equal(canShowMuteParticipant({canManageParticipants: true, participant, record: eligibleRecord}), true);
    participant.isMicrophoneEnabled = false;
    assert.equal(canShowMuteParticipant({canManageParticipants: true, participant, record: eligibleRecord}), false);
    assert.equal(muteResultTranslationKey('already_muted', true), 'meetingRoom.panels.alreadyMuted');
    assert.equal(muteResultTranslationKey('no_active_microphone', true), 'meetingRoom.panels.noActiveMicrophone');
});

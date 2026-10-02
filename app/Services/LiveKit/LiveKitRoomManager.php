<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingMicrophoneMuteResult;
use App\Enums\MeetingProviderState;

interface LiveKitRoomManager
{
    public function create(string $roomName, int $maxParticipants): MeetingProviderState;

    public function delete(string $roomName): MeetingProviderState;

    public function inspect(string $roomName): MeetingProviderState;

    public function removeParticipant(string $roomName, string $identity): MeetingProviderState;

    public function muteParticipantMicrophone(string $roomName, string $identity): MeetingMicrophoneMuteResult;

    public function setParticipantScreenSharePermission(string $roomName, string $identity, bool $allowed): MeetingProviderState;

    /**
     * Asks the provider whether this exact identity is connected to this exact
     * room right now. Both arguments are server-derived; nothing here is ever
     * taken from a client. Returns MeetingProviderState::Unknown when the
     * provider cannot be reached so callers fail closed.
     */
    public function participantPresence(string $roomName, string $identity): LiveKitParticipantPresence;

    /**
     * Asks the provider which screen-share tracks this exact identity currently
     * publishes in this exact room. Both arguments are server-derived from the
     * Meeting and MeetingParticipant, never from a client, because this is the
     * fallback that replaces missing track webhooks.
     *
     * Returns MeetingProviderState::Unknown when the provider cannot be asked,
     * which callers must treat as "retry", never as "not sharing".
     */
    public function screenShareState(string $roomName, string $identity): LiveKitScreenShareState;

    /**
     * Server-side mute of one already-published track. LiveKit has no
     * operation that unpublishes a track, so this is the only authoritative
     * containment available for a track that is already live.
     */
    public function mutePublishedTrack(string $roomName, string $identity, string $trackSid): MeetingProviderState;
}

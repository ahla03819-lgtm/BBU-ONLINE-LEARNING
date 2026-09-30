<?php

namespace App\Enums;

enum MeetingMicrophoneMuteResult: string
{
    case Muted = 'muted';
    case AlreadyMuted = 'already_muted';
    case NoActiveMicrophone = 'no_active_microphone';
    case ParticipantNotPresent = 'participant_not_present';
    case ProviderFailure = 'provider_failure';
}

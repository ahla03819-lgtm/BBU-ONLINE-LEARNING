<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case System = 'system';

    /**
     * The class-channel card for one meeting recording.
     *
     * The card carries no file reference of its own: it points at a
     * MeetingRecording whose state advances from processing to ready, so the card
     * is updated in place instead of a second message being posted.
     */
    case MeetingRecording = 'meeting_recording';
}

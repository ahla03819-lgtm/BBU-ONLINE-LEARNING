<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingRecordingProviderStatus;

/**
 * The provider boundary for server-side meeting recording.
 *
 * Egress is a separate LiveKit service from the Room Service and speaks its own
 * lifecycle, so it gets its own contract rather than being widened onto
 * LiveKitRoomManager. Nothing here accepts a client-supplied value: the room name,
 * the layout and the output path are all derived server-side from the Meeting row
 * and the recording's own public reference.
 *
 * Every method reports provider reachability as Unknown rather than throwing or
 * guessing, so callers can distinguish "the provider said no" from "the provider
 * could not be asked".
 */
interface LiveKitRecordingManager
{
    /**
     * Start a RoomComposite capture of one room.
     *
     * $outputPath is the relative path the provider writes to; the caller owns
     * the decision about where that is and about collecting it afterwards.
     */
    public function startRoomCompositeRecording(string $roomName, string $outputPath, string $layout): LiveKitRecordingStart;

    /**
     * Ask the provider to stop one egress.
     *
     * An egress the provider has never heard of is reported as Complete, because
     * there is nothing left running to stop and the stop succeeded in the only
     * sense the application can act on.
     */
    public function stopRecording(string $egressId): LiveKitRecordingOutcome;

    /**
     * Re-read one egress authoritatively. Null means the provider could not be
     * asked and says nothing at all about whether the egress is running.
     */
    public function inspectRecording(string $egressId): ?LiveKitRecordingStatus;
}
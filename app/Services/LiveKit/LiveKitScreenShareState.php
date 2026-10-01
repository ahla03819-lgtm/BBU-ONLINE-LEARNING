<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingProviderState;

/**
 * Authoritative, server-to-server evidence about one participant's CURRENT
 * screen-share publications, obtained from the LiveKit Room Service.
 *
 * A live screen share is the publication of a SCREEN_SHARE video track. The
 * SCREEN_SHARE_AUDIO companion is reported separately and deliberately never
 * marks a share as started: audio can outlive or precede its video, so treating
 * audio as proof would let an audio-only state masquerade as a screen share.
 *
 * `Unknown` means the provider could not be asked. Callers must retry rather
 * than infer absence, because absence of evidence here is not evidence of
 * absence.
 */
final readonly class LiveKitScreenShareState
{
    /**
     * @param  list<string>  $audioTrackSids
     */
    public function __construct(
        public MeetingProviderState $state,
        public ?string $videoTrackSid = null,
        public array $audioTrackSids = [],
    ) {}

    public function isSharing(): bool
    {
        return $this->state === MeetingProviderState::Active && $this->videoTrackSid !== null;
    }
}

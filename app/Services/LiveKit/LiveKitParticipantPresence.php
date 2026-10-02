<?php

namespace App\Services\LiveKit;

use App\Enums\MeetingProviderState;
use Carbon\CarbonImmutable;

/**
 * Authoritative, server-to-server evidence about one LiveKit participant.
 *
 * `Active` means the provider confirmed the identity is connected right now.
 * `Ended` means the provider confirmed it is not in the room. `Unknown` means
 * the provider could not be asked, so callers must fail closed.
 */
final readonly class LiveKitParticipantPresence
{
    public function __construct(
        public MeetingProviderState $state,
        public ?string $participantSid = null,
        public ?CarbonImmutable $joinedAt = null,
    ) {}

    public function isPresent(): bool
    {
        return $this->state === MeetingProviderState::Active && $this->participantSid !== null;
    }
}

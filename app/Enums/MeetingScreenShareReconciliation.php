<?php

namespace App\Enums;

/**
 * Outcome of reconciling a screen-share request against authoritative provider
 * state.
 *
 * Unknown is deliberately distinct from DefinitelyNotSharing: a provider that
 * cannot be asked tells us nothing, and treating silence as absence is exactly
 * how a genuinely live share got expired once already.
 */
enum MeetingScreenShareReconciliation: string
{
    /** The provider reported a canonical SCREEN_SHARE VIDEO publication. */
    case SharingConfirmed = 'sharing_confirmed';

    /**
     * The provider authoritatively sees a canonical SCREEN_SHARE VIDEO, but the
     * request is still only Approved and its approval window has already lapsed.
     *
     * This is never promoted to Sharing: the publication is unauthorised, and
     * silently creating a new authorisation window after the host's approval
     * expired would turn a deadline into a suggestion. It is reported so the
     * caller can contain the publication instead.
     */
    case LapsedLivePublication = 'lapsed_live_publication';

    /** The provider definitively reported the participant or has no screen video. */
    case DefinitelyNotSharing = 'definitely_not_sharing';

    /** The provider could not be asked. Retry; never infer absence. */
    case ProviderUnreachable = 'provider_unreachable';
}

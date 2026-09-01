import React from 'react';
import Icon from '../../UI/Icon';
import UserAvatar from '../../UI/UserAvatar';

function avatarUrlFromMetadata(metadata) {
    try {
        const avatarUrl = JSON.parse(metadata || '{}').avatar_url;
        if (typeof avatarUrl !== 'string') return null;

        const url = new URL(avatarUrl, window.location.origin);

        return url.origin === window.location.origin && url.pathname.startsWith('/storage/user-avatars/') ? url.toString() : null;
    } catch {
        return null;
    }
}

export default function MeetingParticipantAvatar({participant, size = 'xl'}) {
    const name = participant?.name?.trim();

    if (!name) {
        return <span className="grid h-28 w-28 place-items-center rounded-full border-4 border-white/15 bg-slate-700 text-slate-300 shadow-xl sm:h-36 sm:w-36" aria-label="Participant avatar unavailable"><Icon name="user" className="h-12 w-12 sm:h-16 sm:w-16"/></span>;
    }

    return <UserAvatar name={name} avatarUrl={avatarUrlFromMetadata(participant.metadata)} size={size} alt={`Profile photo for ${name}`} className="border-4 border-white/20 shadow-2xl shadow-black/30"/>;
}

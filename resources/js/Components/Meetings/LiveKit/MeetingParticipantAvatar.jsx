import React, {useEffect, useState} from 'react';
import Icon from '../../UI/Icon';

function safeAvatarUrl(value) {
    if (typeof value !== 'string') return null;

    try {
        const url = new URL(value, window.location.origin);

        return url.origin === window.location.origin && url.pathname.startsWith('/storage/user-avatars/') ? url.toString() : null;
    } catch {
        return null;
    }
}

function avatarUrlFromMetadata(metadata) {
    try {
        return safeAvatarUrl(JSON.parse(metadata || '{}').avatar_url);
    } catch {
        return null;
    }
}

export default function MeetingParticipantAvatar({participant, name: suppliedName, avatarUrl: suppliedAvatarUrl, size = 'xl', alt, className = ''}) {
    const name = suppliedName?.trim() || participant?.name?.trim();
    const avatarUrl = safeAvatarUrl(suppliedAvatarUrl) || avatarUrlFromMetadata(participant?.metadata);
    const [imageFailed, setImageFailed] = useState(false);
    const initials = name?.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'E';
    const sizes = {sm: 'h-8 w-8 text-xs', md: 'h-10 w-10 text-sm', lg: 'h-20 w-20 text-2xl', xl: 'h-28 w-28 text-4xl sm:h-36 sm:w-36'};
    const avatarClass = `border-4 border-white/20 shadow-2xl shadow-black/30 ${className}`;

    useEffect(() => setImageFailed(false), [avatarUrl]);

    if (!name) {
        return <span className="grid h-28 w-28 place-items-center rounded-full border-4 border-white/15 bg-slate-700 text-slate-300 shadow-xl sm:h-36 sm:w-36" aria-label="Participant avatar unavailable"><Icon name="user" className="h-12 w-12 sm:h-16 sm:w-16"/></span>;
    }

    if (avatarUrl && !imageFailed) {
        return <img src={avatarUrl} alt={alt ?? `Profile photo for ${name}`} onError={() => setImageFailed(true)} className={`inline-flex shrink-0 rounded-full object-cover ${sizes[size] || sizes.md} ${avatarClass}`}/>;
    }

    return <span aria-hidden="true" className={`inline-flex shrink-0 items-center justify-center rounded-full bg-indigo-100 font-bold text-indigo-700 ${sizes[size] || sizes.md} ${avatarClass}`}>{initials}</span>;
}

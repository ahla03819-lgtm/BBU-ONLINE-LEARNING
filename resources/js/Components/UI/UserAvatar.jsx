import React, {useEffect, useState} from 'react';
import {usePage} from '@inertiajs/react';

export default function UserAvatar({name = '', avatarUrl, size = 'md', alt, className = ''}) {
    const currentUser = usePage().props.auth?.user;
    const [imageFailed, setImageFailed] = useState(false);
    const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'E';
    const sizes = {sm: 'h-8 w-8 text-xs', md: 'h-10 w-10 text-sm', lg: 'h-20 w-20 text-2xl', xl: 'h-28 w-28 text-4xl sm:h-36 sm:w-36'};
    const resolvedAvatarUrl = avatarUrl === undefined && currentUser?.name === name ? currentUser.avatar_url : avatarUrl;

    useEffect(() => setImageFailed(false), [resolvedAvatarUrl]);

    if (resolvedAvatarUrl && !imageFailed) return <img src={resolvedAvatarUrl} alt={alt ?? `${name}'s profile photo`} onError={() => setImageFailed(true)} className={`inline-flex shrink-0 rounded-full border-2 border-white object-cover shadow-sm ${sizes[size] || sizes.md} ${className}`}/>;

    return <span aria-hidden="true" className={`inline-flex shrink-0 items-center justify-center rounded-full bg-indigo-100 font-bold text-indigo-700 ${sizes[size] || sizes.md} ${className}`}>{initials}</span>;
}

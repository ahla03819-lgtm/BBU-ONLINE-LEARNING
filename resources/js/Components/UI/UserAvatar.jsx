import React from 'react';

export default function UserAvatar({name = '', size = 'md'}) {
    const initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'E';
    const sizes = {sm: 'h-8 w-8 text-xs', md: 'h-10 w-10 text-sm'};

    return <span aria-hidden="true" className={`inline-flex shrink-0 items-center justify-center rounded-xl bg-indigo-100 font-bold text-indigo-700 ${sizes[size] || sizes.md}`}>{initials}</span>;
}

import React from 'react';
import Icon from '../UI/Icon';
import {ConversationAvatar} from './ConversationSidebar';

export default function ConversationHeader({conversation, calling, onCall, onSearch, onInfo, onBack}) {
    return <header className="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 sm:px-5 sm:py-4">
        <div className="flex min-w-0 items-center gap-3">
            <button type="button" onClick={onBack} className="rounded-xl p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Back to conversations"><Icon name="arrow-left" className="h-5 w-5"/></button>
            <ConversationAvatar conversation={conversation}/><div className="min-w-0"><h2 className="truncate font-bold text-slate-900">{conversation.name}</h2><p className="text-xs text-slate-500">{conversation.type === 'group' ? `${conversation.members.length} active members` : 'Private chat'}</p></div>
        </div>
        <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
            <button type="button" onClick={onSearch} className="rounded-xl bg-slate-50 p-2 text-slate-600 hover:bg-blue-50 hover:text-blue-800" aria-label="Search conversation"><Icon name="search" className="h-4 w-4"/></button>
            <button type="button" disabled={calling} onClick={() => onCall('audio')} className="rounded-xl bg-blue-50 p-2 text-blue-800 disabled:opacity-50" aria-label="Start audio call"><Icon name="phone" className="h-4 w-4"/></button>
            <button type="button" disabled={calling} onClick={() => onCall('video')} className="rounded-xl bg-blue-800 p-2 text-white disabled:opacity-50" aria-label="Start video call"><Icon name="video" className="h-4 w-4"/></button>
            <button type="button" onClick={onInfo} className="rounded-xl bg-slate-50 p-2 text-slate-600 hover:bg-blue-50 hover:text-blue-800" aria-label="Conversation information"><Icon name="more" className="h-4 w-4"/></button>
        </div>
    </header>;
}

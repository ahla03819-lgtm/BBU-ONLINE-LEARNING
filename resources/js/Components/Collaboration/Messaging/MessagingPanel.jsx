import React, {useState} from 'react';
import MessageComposer from './MessageComposer';
import MessageList from './MessageList';
import PresenceList from './PresenceList';
import TypingIndicator from './TypingIndicator';
import useChannelMessages from '../../../Hooks/useChannelMessages';

export default function MessagingPanel({schoolClass, channel, currentUser, canCreate, canModerate}) {
    const messaging = useChannelMessages({schoolClassId: schoolClass.id, channelId: channel.id, user: currentUser});
    const [reply, setReply] = useState(null);
    const safely = operation => operation.catch(error => window.alert(error.message));
    const edit = message => { const body = window.prompt('Edit message', message.body); if (body !== null && body.trim()) safely(messaging.update(message, body)); };
    const moderate = message => { const reason = window.prompt('Moderation reason'); if (reason?.trim()) safely(messaging.moderate(message, reason)); };
    return <section className="card mt-5">
        <div className="mb-4 flex items-center justify-between"><h3 className="font-semibold">Messages</h3><span className={`text-xs ${messaging.connection === 'connected' ? 'text-emerald-600' : 'text-amber-600'}`}>{messaging.connection}</span></div>
        <div className="grid gap-4 xl:grid-cols-[1fr_200px]"><div>
            {messaging.loadError && <p className="mb-3 rounded-lg bg-red-50 p-2 text-sm text-red-700">{messaging.loadError}</p>}
            <div className="max-h-[32rem] overflow-y-auto pr-1"><MessageList messages={messaging.messages} hasMore={messaging.hasMore} loading={messaging.loading} loadOlder={() => safely(messaging.loadOlder())} currentUser={currentUser} canModerate={canModerate} onReply={setReply} onEdit={edit} onHide={message => safely(messaging.hide(message))} onModerate={moderate} onRetry={messaging.retry}/></div>
            <TypingIndicator names={messaging.typing}/>
            {canCreate ? <MessageComposer reply={reply} onCancelReply={() => setReply(null)} onSend={messaging.send} onTyping={messaging.whisperTyping}/> : <p className="mt-4 border-t pt-4 text-sm text-slate-500">This channel is read-only.</p>}
        </div><PresenceList members={messaging.members}/></div>
    </section>;
}

import {useEffect, useState} from 'react';
import {echo} from '../realtime/echo';
import {useAppSounds} from '../Sound/AppSounds';

export default function useIncomingCalls(user) {
    const [call, setCall] = useState(null);
    const {play} = useAppSounds();

    useEffect(() => {
        if (!echo || !user?.id) return undefined;
        const name = `incoming-call.${user.id}`;
        const channel = echo.private(name).listen('.conversation.call.started', event => setCall(event.call)).listen('.conversation.call.accepted', event => setCall((current) => current?.uuid === event.call.uuid ? {...current, status: 'active'} : current)).listen('.conversation.call.declined', event => setCall((current) => current?.uuid === event.call.uuid ? null : current)).listen('.conversation.call.cancelled', event => setCall((current) => current?.uuid === event.call.uuid ? null : current));
        return () => echo.leave(name);
    }, [user?.id]);

    useEffect(() => {
        if (!call) return undefined;
        if (call.status !== 'ringing') {
            play('notification', call.uuid);
            return undefined;
        }
        play('incoming-call', call.uuid);
        const ringtone = window.setInterval(() => play('incoming-call', call.uuid), 1800);
        return () => window.clearInterval(ringtone);
    }, [call, play]);

    const respond = async (decision) => {
        if (!call) return false;
        const current = call;
        const response = await fetch(`/conversation-calls/${current.uuid}/respond`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({decision}),
        });

        if (!response.ok) throw new Error('Unable to update the call.');
        setCall(null);
        return true;
    };

    return {call, respond};
}

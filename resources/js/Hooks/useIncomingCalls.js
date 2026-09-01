import {useEffect, useState} from 'react';
import {router} from '@inertiajs/react';
import {echo} from '../realtime/echo';
import {useAppSounds} from '../Sound/AppSounds';

export default function useIncomingCalls(user) {
    const [call, setCall] = useState(null); const {play} = useAppSounds();
    useEffect(() => { if (!echo || !user?.id) return undefined; const name = `incoming-call.${user.id}`; const channel = echo.private(name).listen('.conversation.call.started', event => setCall(event.call)); return () => echo.leave(name); }, [user?.id]);
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
    const respond = async decision => {
        if (!call) return;
        const current = call;
        if (current.status === 'active') {
            setCall(null);
            router.visit(`/conversation-calls/${current.uuid}/room`);
            return;
        }
        const response = await fetch(`/conversation-calls/${current.uuid}/respond`, {method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content, Accept: 'application/json', 'Content-Type': 'application/json'}, body: JSON.stringify({decision})});
        if (!response.ok) throw new Error('Unable to update the call.');
        setCall(null);
        if (decision === 'accepted') router.visit(`/conversation-calls/${current.uuid}/room`);
    };
    return {call, respond};
}

import React, {useEffect} from 'react';
import {Head} from '@inertiajs/react';
import Layout from '../../Layouts/AuthenticatedLayout';
import {usePersistentConversationCall} from '../../Providers/PersistentConversationCallProvider';

export default function CallRoom({call}) {
    const {connectCall} = usePersistentConversationCall();

    useEffect(() => {
        if (!call?.uuid) return;
        connectCall(call, {mode: 'full'}).catch(() => undefined);
    }, [call, connectCall]);

    return <Layout><Head title="BBU Live Call"/><div className="flex min-h-[24rem] items-center justify-center rounded-3xl border border-slate-200 bg-slate-50 p-8 text-center"><div><p className="text-xs font-bold uppercase tracking-[0.28em] text-sky-700">BBU Live Call</p><h1 className="mt-3 text-2xl font-bold text-slate-900">Joining {call?.name || 'call'}…</h1><p className="mt-2 text-sm text-slate-600">The active call is owned by the persistent provider to keep the LiveKit room alive across navigation.</p></div></div></Layout>;
}

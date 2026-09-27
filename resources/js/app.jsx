import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import {AppSoundsProvider} from './Sound/AppSounds';
import {PersistentMeetingProvider} from './Providers/PersistentMeetingProvider';
import {PersistentConversationCallProvider} from './Providers/PersistentConversationCallProvider';
import './realtime/echo';

function InertiaConversationCallLayout({children}) {
    return <PersistentConversationCallProvider>{children}</PersistentConversationCallProvider>;
}

createInertiaApp({
    title: (title) => title ? `${title} · BBU ONLINE LEARNING` : 'BBU ONLINE LEARNING',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        return pages[`./Pages/${name}.jsx`]();
    },
    layout: () => InertiaConversationCallLayout,
    setup({ el, App, props }) { createRoot(el).render(<AppSoundsProvider><PersistentMeetingProvider><App {...props} /></PersistentMeetingProvider></AppSoundsProvider>); },
    progress: { color: '#075ca8' },
});

import React from 'react';
import {createRoot} from 'react-dom/client';
import {createInertiaApp} from '@inertiajs/react';
import {AppSoundsProvider} from './Sound/AppSounds';
import {PersistentMeetingProvider} from './Providers/PersistentMeetingProvider';
import {PersistentConversationCallProvider} from './Providers/PersistentConversationCallProvider';
import {LocaleProvider} from './i18n/LocaleProvider';
import './realtime/echo';

function InertiaConversationCallLayout({children}) {
    // The locale lives here, inside the Inertia tree, so it can read the shared
    // account preference and stay mounted across visits.
    return <LocaleProvider><PersistentMeetingProvider><PersistentConversationCallProvider>{children}</PersistentConversationCallProvider></PersistentMeetingProvider></LocaleProvider>;
}

createInertiaApp({
    title: (title) => title ? `${title} · BBU ONLINE LEARNING` : 'BBU ONLINE LEARNING',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        return pages[`./Pages/${name}.jsx`]();
    },
    layout: () => InertiaConversationCallLayout,
    setup({ el, App, props }) { createRoot(el).render(<AppSoundsProvider><App {...props} /></AppSoundsProvider>); },
    progress: { color: '#075ca8' },
});

import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import {AppSoundsProvider} from './Sound/AppSounds';
import {PersistentMeetingProvider} from './Providers/PersistentMeetingProvider';
import './realtime/echo';

createInertiaApp({
    title: (title) => title ? `${title} · BBU ONLINE LEARNING` : 'BBU ONLINE LEARNING',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        return pages[`./Pages/${name}.jsx`]();
    },
    setup({ el, App, props }) { createRoot(el).render(<AppSoundsProvider><PersistentMeetingProvider><App {...props} /></PersistentMeetingProvider></AppSoundsProvider>); },
    progress: { color: '#075ca8' },
});

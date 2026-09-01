import React from 'react';
import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import {AppSoundsProvider} from './Sound/AppSounds';
import './realtime/echo';

createInertiaApp({
    title: (title) => title ? `${title} · BBU Online Learning` : 'BBU Online Learning',
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        return pages[`./Pages/${name}.jsx`]();
    },
    setup({ el, App, props }) { createRoot(el).render(<AppSoundsProvider><App {...props} /></AppSoundsProvider>); },
    progress: { color: '#075ca8' },
});

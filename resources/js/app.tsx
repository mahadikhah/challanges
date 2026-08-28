import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import { DirectionLayout } from '@/hooks/use-document-direction';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        const pageLayouts = (() => {
            switch (true) {
                case name === 'welcome':
                    return [];
                case name.startsWith('auth/'):
                    return [AuthLayout];
                case name.startsWith('settings/'):
                    return [AppLayout, SettingsLayout];
                default:
                    return [AppLayout];
            }
        })();

        // `DirectionLayout` must come first so `useDocumentDirection` — which
        // needs Inertia's context — wraps every page no matter its own layouts.
        return [DirectionLayout, ...pageLayouts];
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

import { createInertiaApp } from '@inertiajs/svelte';
import AppLayout from '@/layouts/AppLayout.svelte';
import AuthLayout from '@/layouts/AuthLayout.svelte';
import PlayerLayout from '@/layouts/PlayerLayout.svelte';
import SettingsLayout from '@/layouts/settings/Layout.svelte';
import { initializeFlashToast } from '@/lib/flash-toast';
import {
    currentTaikoScope,
    initializeTaikoRouteDefaults,
    initializeTaikoVersionAccent,
    taikoVersionAccent,
} from '@/lib/taiko-version';
import { initializeTheme } from '@/lib/theme.svelte';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

initializeTaikoRouteDefaults();
initializeTaikoVersionAccent();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('admin/'):
                return AppLayout;
            case name.startsWith('settings/'):
                return [PlayerLayout, SettingsLayout];
            default:
                return PlayerLayout;
        }
    },
    progress: {
        color: taikoVersionAccent(currentTaikoScope()).progress,
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

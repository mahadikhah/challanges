import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

import { MiniApp } from './app';
import './miniapp.css';

/*
 * Telegram Mini App entry point.
 *
 * This is a standalone React SPA with its own Vite entry — deliberately NOT
 * Inertia. Telegram only reveals the user's identity (via `initData`) after
 * the webview loads, so the app boots empty, verifies `initData` against the
 * API, and exchanges it for a short-lived Sanctum bearer token.
 *
 * The document's `lang`/`dir` are already set server-side by the Blade shell,
 * so Farsi renders right-to-left on first paint with no layout shift.
 */
const rootElement = document.getElementById('app');

if (rootElement) {
    createRoot(rootElement).render(
        <StrictMode>
            <MiniApp />
        </StrictMode>,
    );
}

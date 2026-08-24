import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';

/*
 * Telegram Mini App entry point.
 *
 * This is a standalone React SPA with its own Vite entry — deliberately NOT
 * Inertia. Telegram only reveals the user's identity (via `initData`) after the
 * webview loads, so the app boots empty, verifies `initData` against the API,
 * and exchanges it for a short-lived Sanctum bearer token. Real bootstrapping
 * (initData verification, typed API client, routing) lands in Phase 5; this is
 * the registered-but-minimal mount that proves the separate bundle compiles.
 */
const rootElement = document.getElementById('app');

if (rootElement) {
    createRoot(rootElement).render(
        <StrictMode>
            <main>
                <h1>Telegram Mini App</h1>
                <p>Coming soon.</p>
            </main>
        </StrictMode>,
    );
}

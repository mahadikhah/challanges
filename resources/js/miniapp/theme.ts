import type { ThemeParams } from './telegram';

/**
 * Map Telegram's `themeParams` onto CSS custom properties.
 *
 * The Mini App does not run its own dark/light system — Telegram owns the
 * look, and the theme arrives per user per platform setting. Every value has
 * a fallback in `miniapp.css` (a light set matching Telegram's defaults), so
 * the app renders sensibly in a plain browser too and never flashes an
 * unstyled frame while waiting for a param that is never coming.
 *
 * `themeChanged` re-runs this, so a user flipping Telegram's theme mid-session
 * recolours the app immediately — including across the day/night boundary,
 * which is exactly when a wrong-colour app looks broken.
 */

const PROPERTIES = {
    bg_color: '--tg-bg',
    text_color: '--tg-text',
    hint_color: '--tg-hint',
    link_color: '--tg-link',
    button_color: '--tg-button',
    button_text_color: '--tg-button-text',
    secondary_bg_color: '--tg-secondary-bg',
    section_bg_color: '--tg-section-bg',
    section_separator_color: '--tg-separator',
} as const;

export function applyTheme(params: ThemeParams): void {
    const root = document.documentElement.style;

    for (const [param, property] of Object.entries(PROPERTIES)) {
        const value = params[param as keyof ThemeParams];

        if (typeof value === 'string' && value !== '') {
            root.setProperty(property, value);
        }
    }
}

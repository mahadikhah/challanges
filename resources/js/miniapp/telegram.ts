/**
 * The slice of Telegram's `telegram-web-app.js` the Mini App uses, typed
 * locally.
 *
 * `@telegram-apps/sdk` is deliberately not a dependency: the platform script
 * is already loaded by the Blade shell before this bundle runs, and a typed
 * wrapper over the few methods we call is a smaller commitment than a client
 * library on a mobile network. Anything `webApp()` returns null for means
 * "opened in a plain browser" — every caller must degrade, not assume.
 *
 * Only the members actually used are declared; the real object carries far
 * more (see Telegram's web_app docs). When a new capability is needed, add
 * its member here rather than reaching for `any`.
 */

export type ThemeParams = {
    bg_color?: string;
    text_color?: string;
    hint_color?: string;
    link_color?: string;
    button_color?: string;
    button_text_color?: string;
    secondary_bg_color?: string;
    section_bg_color?: string;
    section_separator_color?: string;
    header_bg_color?: string;
    accent_text_color?: string;
    destructive_text_color?: string;
};

type BackButton = {
    show: () => void;
    hide: () => void;
    onClick: (callback: () => void) => void;
    offClick: (callback: () => void) => void;
};

type MainButton = {
    setText: (text: string) => void;
    on: (event: string, callback: () => void) => void;
    off: (event: string, callback: () => void) => void;
    show: () => void;
    hide: () => void;
    enable: () => void;
    disable: () => void;
    showProgress: (leaveActive?: boolean) => void;
    hideProgress: () => void;
    onClick: (callback: () => void) => void;
    offClick: (callback: () => void) => void;
};

type HapticFeedback = {
    notificationOccurred: (type: 'error' | 'success' | 'warning') => void;
};

type WebApp = {
    initData: string;
    initDataUnsafe: {
        user?: {
            id: number;
            first_name?: string;
        };
        start_param?: string;
    };
    version: string;
    colorScheme: 'light' | 'dark';
    themeParams: ThemeParams;
    isExpanded: boolean;
    ready: () => void;
    expand: () => void;
    onEvent: (eventType: string, callback: () => void) => void;
    offEvent: (eventType: string, callback: () => void) => void;
    openTelegramLink: (url: string) => void;
    BackButton: BackButton;
    MainButton: MainButton;
    HapticFeedback?: HapticFeedback;
};

declare global {
    interface Window {
        Telegram?: {
            WebApp: WebApp;
        };
    }
}

/**
 * The live Telegram WebApp, or null outside Telegram (a desktop browser
 * during development, most commonly).
 */
export function webApp(): WebApp | null {
    return window.Telegram?.WebApp ?? null;
}

/**
 * Whether the WebApp version supports an event. Telegram gates newer APIs by
 * `version`; calling them on an old client throws or silently does nothing.
 */
export function supports(app: WebApp, minimumVersion: string): boolean {
    const [major, minor] = app.version.split('.').map(Number);
    const [minMajor, minMinor] = minimumVersion.split('.').map(Number);

    return major > minMajor || (major === minMajor && minor >= minMinor);
}

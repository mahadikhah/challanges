import type { TranslationReplacements } from '@/lib/i18n';
import { translate } from '@/lib/i18n';
import type { LocalizationPayload } from '@/types/localization';

/**
 * The Mini App has no Inertia, so its locale payload is embedded in the Blade
 * shell as JSON (see resources/views/miniapp.blade.php) and read once at boot.
 * Same shape the Inertia surfaces receive as shared props.
 */
const fallback: LocalizationPayload = {
    locale: 'en',
    direction: 'ltr',
    locales: [],
    translations: {},
};

function readPayload(): LocalizationPayload {
    const element = document.getElementById('localization');

    if (!element?.textContent) {
        return fallback;
    }

    try {
        return {
            ...fallback,
            ...(JSON.parse(
                element.textContent,
            ) as Partial<LocalizationPayload>),
        };
    } catch {
        return fallback;
    }
}

export const localization = readPayload();

export function t(key: string, replacements?: TranslationReplacements): string {
    return translate(localization.translations, key, replacements);
}

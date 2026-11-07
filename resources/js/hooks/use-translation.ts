import { usePage } from '@inertiajs/react';
import type { TranslationReplacements } from '@/lib/i18n';
import { translate } from '@/lib/i18n';

/**
 * Translations for the active locale, delivered as Inertia shared props by
 * HandleInertiaRequests.
 *
 * `isRtl` is for the rare case where a component needs to branch in JS — prefer
 * Tailwind's logical utilities (`ms-*`, `me-*`, `text-start`) and the `rtl:`
 * variant, which work off the `dir` attribute the server already set.
 */
export function useTranslation() {
    const { locale, locales, direction, translations } = usePage().props;

    return {
        t: (key: string, replacements?: TranslationReplacements): string =>
            translate(translations, key, replacements),
        locale,
        locales,
        direction,
        isRtl: direction === 'rtl',
    };
}

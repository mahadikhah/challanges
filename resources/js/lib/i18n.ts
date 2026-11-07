import type { Translations } from '@/types/localization';

export type TranslationReplacements = Record<string, string | number>;

/**
 * Look up a flattened `group.key` line and fill Laravel-style `:name`
 * placeholders.
 *
 * Shared by both frontends: the Inertia surfaces reach it through
 * `useTranslation()`, the Mini App SPA through its own `t()`. A missing key
 * renders as the key itself, so an untranslated string is visible in the UI
 * rather than silently blank.
 */
export function translate(
    translations: Translations,
    key: string,
    replacements?: TranslationReplacements,
): string {
    const line = translations[key] ?? key;

    if (!replacements) {
        return line;
    }

    return Object.entries(replacements).reduce(
        (carry, [name, value]) => carry.replaceAll(`:${name}`, String(value)),
        line,
    );
}

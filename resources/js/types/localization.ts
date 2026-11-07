export type Direction = 'ltr' | 'rtl';

export type LocaleOption = {
    code: string;
    native: string;
    direction: Direction;
};

/** Flattened `group.key` => line, as produced by App\Services\Localization. */
export type Translations = Record<string, string>;

/**
 * The localization payload both frontends receive: as Inertia shared props on
 * the admin/website surfaces, and embedded in a script tag in the Mini App
 * shell (which has no Inertia).
 */
export type LocalizationPayload = {
    locale: string;
    direction: Direction;
    locales: LocaleOption[];
    translations: Translations;
};

import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { useEffect } from 'react';

/**
 * Keeps the document's `dir` and `lang` attributes in step with the locale
 * the server resolved for the current request.
 *
 * Inertia swaps the page without reloading the document, so the attributes
 * the Blade shell stamped on `<html>` go stale the moment a visitor switches
 * language — the copy would flip to Farsi while the layout stayed LTR until a
 * manual refresh. Tailwind's logical utilities (`ms-*`, `me-*`, `text-start`)
 * and the `rtl:` variant all key off `dir`, so this one effect is what makes
 * the whole layout mirror without a reload.
 */
export function useDocumentDirection() {
    const { locale, direction } = usePage().props;

    useEffect(() => {
        document.documentElement.lang = locale;
        document.documentElement.dir = direction;
    }, [locale, direction]);
}

/**
 * The mount point for `useDocumentDirection`. The hook reads `usePage()`,
 * which only works beneath Inertia's context provider — wrapping the app from
 * the outside (`withApp`) renders *above* the provider and throws "usePage
 * must be used within the Inertia component". So instead, `app.tsx`'s layout
 * resolver returns `[DirectionLayout, ...page layouts]`, placing this inside
 * the provider on every page without touching page components themselves.
 */
export function DirectionLayout({ children }: { children: ReactNode }) {
    useDocumentDirection();

    return children;
}

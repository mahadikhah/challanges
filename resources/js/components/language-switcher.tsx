import { router } from '@inertiajs/react';
import type { HTMLAttributes } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { update as updateLocale } from '@/routes/locale';

/**
 * Switches the interface language. Posts to the server rather than flipping a
 * client flag, because the locale also decides the document direction and every
 * server-rendered string — the redirect back re-renders with the new props.
 */
export default function LanguageSwitcher({
    className = '',
    ...props
}: HTMLAttributes<HTMLDivElement>) {
    const { t, locale, locales } = useTranslation();

    return (
        <div
            role="group"
            aria-label={t('common.language')}
            className={cn(
                'inline-flex gap-1 rounded-lg bg-neutral-100 p-1 dark:bg-neutral-800',
                className,
            )}
            {...props}
        >
            {locales.map(({ code, native }) => (
                <button
                    key={code}
                    type="button"
                    lang={code}
                    aria-current={locale === code}
                    onClick={() =>
                        router.post(
                            updateLocale.url(),
                            { locale: code },
                            { preserveScroll: true },
                        )
                    }
                    className={cn(
                        'flex items-center rounded-md px-3.5 py-1.5 text-sm transition-colors',
                        locale === code
                            ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                >
                    {native}
                </button>
            ))}
        </div>
    );
}

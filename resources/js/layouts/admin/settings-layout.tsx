import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import { cn, toUrl } from '@/lib/utils';
import { show } from '@/routes/admin/settings';
import type { NavItem } from '@/types';

/**
 * The admin settings panel's shared shell: heading plus a vertical tab
 * aside, mirroring the user-facing `/settings` layout (separate routes per
 * tab, no tab component — back/forward and deep links work for free).
 */
const TABS = [
    'economy',
    'challenges',
    'access',
    'ai',
    'observability',
] as const;

export default function AdminSettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { t } = useTranslation();

    // Built in-component: the labels need `t()`, which only exists inside
    // the Inertia render tree.
    const navItems: NavItem[] = TABS.map((tab) => ({
        title: t(`admin.settings.tabs.${tab}`),
        href: show.url(tab),
        icon: null,
    }));

    return (
        <div className="px-4 py-6">
            <Heading
                title={t('admin.settings.title')}
                description={t('admin.settings.description')}
            />

            <div className="flex flex-col gap-12 lg:flex-row">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav
                        className="flex flex-col gap-1"
                        aria-label={t('admin.settings.title')}
                    >
                        {navItems.map((item, index) => (
                            <Button
                                key={`${toUrl(item.href)}-${index}`}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn('w-full justify-start', {
                                    'bg-muted': isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>
                                    {item.icon && (
                                        <item.icon className="h-4 w-4" />
                                    )}
                                    {item.title}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-3">{children}</section>
                </div>
            </div>
        </div>
    );
}

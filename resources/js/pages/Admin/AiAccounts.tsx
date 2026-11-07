import { Head } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { create, edit as editRoute, index } from '@/routes/admin/ai-accounts';
import { index as settingsIndex } from '@/routes/admin/settings';
import type { AccountRow, CapabilityOption } from './AiAccounts/account-form';

export default function AiAccounts({
    accounts,
    capabilities,
}: {
    accounts: AccountRow[];
    capabilities: CapabilityOption[];
}) {
    const { t, locale } = useTranslation();

    const grouped = capabilities
        .map((capability) => ({
            capability,
            rows: accounts.filter(
                (account) => account.capability.id === capability.id,
            ),
        }))
        .filter((group) => group.rows.length > 0);

    return (
        <>
            <Head title={t('admin.ai_accounts.title')} />

            <h1 className="sr-only">{t('admin.ai_accounts.title')}</h1>

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <Heading
                            variant="small"
                            title={t('admin.ai_accounts.title')}
                            description={t('admin.ai_accounts.description')}
                        />

                        <Button asChild size="sm">
                            <Link href={create.url()}>
                                <Plus />
                                {t('admin.ai_accounts.new')}
                            </Link>
                        </Button>
                    </div>

                    {accounts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.ai_accounts.empty')}
                        </p>
                    ) : (
                        grouped.map(({ capability, rows }) => (
                            <section key={capability.id} className="space-y-3">
                                <h2 className="text-lg font-medium tracking-tight">
                                    {capability.label}
                                </h2>

                                <div className="space-y-3">
                                    {rows.map((account) => (
                                        <AccountCard
                                            key={account.id}
                                            account={account}
                                            locale={locale}
                                        />
                                    ))}
                                </div>
                            </section>
                        ))
                    )}
                </div>
            </div>
        </>
    );
}

function AccountCard({
    account,
    locale,
}: {
    account: AccountRow;
    locale: string;
}) {
    const { t } = useTranslation();

    const formatWhen = (iso: string | null): string =>
        iso === null
            ? '—'
            : new Intl.DateTimeFormat(locale, {
                  dateStyle: 'medium',
                  timeStyle: 'short',
              }).format(new Date(iso));

    return (
        <Card>
            <CardContent className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{account.name}</span>

                        <Badge variant="secondary">
                            {account.driver_label}
                        </Badge>

                        {!account.is_active && (
                            <Badge variant="outline">
                                {t('admin.ai_accounts.status.inactive')}
                            </Badge>
                        )}

                        {!account.is_configured && (
                            <Badge variant="destructive">
                                {t('admin.ai_accounts.status.unconfigured')}
                            </Badge>
                        )}

                        {account.is_cooling_down && (
                            <Badge variant="outline">
                                {t('admin.ai_accounts.status.cooling_down')}
                            </Badge>
                        )}
                    </div>

                    <Button asChild variant="ghost" size="sm">
                        <Link href={editRoute(account.id).url}>
                            {t('admin.ai_accounts.edit')}
                        </Link>
                    </Button>
                </div>

                <p className="text-xs text-muted-foreground" dir="ltr">
                    {account.driver} · {account.model ?? '—'}
                </p>

                {account.last_failure_reason !== null && (
                    <p className="text-xs text-muted-foreground">
                        {t('admin.ai_accounts.status.last_failed')}:{' '}
                        <span dir="ltr" className="font-mono">
                            {account.last_failure_reason}
                        </span>
                        {account.unavailable_until !== null &&
                            ` · ${formatWhen(account.unavailable_until)}`}
                    </p>
                )}

                {account.last_succeeded_at !== null && (
                    <p className="text-xs text-muted-foreground">
                        {t('admin.ai_accounts.status.last_succeeded')}:{' '}
                        {formatWhen(account.last_succeeded_at)}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

AiAccounts.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: settingsIndex.url(),
        },
        {
            title: 'AI providers',
            titleKey: 'admin.ai_accounts.title',
            href: index.url(),
        },
    ],
};

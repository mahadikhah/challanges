import { Head } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, index } from '@/routes/admin/ai-accounts';
import { index as settingsIndex } from '@/routes/admin/settings';
import { AccountForm } from './account-form';
import type {
    AccountRow,
    CapabilityOption,
    DriverOption,
} from './account-form';

export default function Edit({
    account,
    capabilities,
    drivers,
}: {
    account: AccountRow;
    capabilities: CapabilityOption[];
    drivers: DriverOption[];
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={account.name} />

            <h1 className="sr-only">{account.name}</h1>

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <Heading
                            variant="small"
                            title={account.name}
                            description={t('admin.ai_accounts.description')}
                        />

                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                if (
                                    window.confirm(
                                        t('admin.ai_accounts.delete_confirm'),
                                    )
                                ) {
                                    router.delete(destroy(account.id).url);
                                }
                            }}
                        >
                            {t('admin.ai_accounts.delete')}
                        </Button>
                    </div>

                    <div className="max-w-2xl">
                        <AccountForm
                            account={account}
                            capabilities={capabilities}
                            drivers={drivers}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

Edit.layout = {
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
        {
            title: 'Edit',
            titleKey: 'admin.ai_accounts.edit',
            href: '/admin/ai-accounts',
        },
    ],
};

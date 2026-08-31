import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { useTranslation } from '@/hooks/use-translation';
import { create, index } from '@/routes/admin/ai-accounts';
import { index as settingsIndex } from '@/routes/admin/settings';
import { AccountForm } from './account-form';
import type { CapabilityOption, DriverOption } from './account-form';

export default function Create({
    capabilities,
    drivers,
}: {
    capabilities: CapabilityOption[];
    drivers: DriverOption[];
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('admin.ai_accounts.new')} />

            <h1 className="sr-only">{t('admin.ai_accounts.new')}</h1>

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('admin.ai_accounts.new')}
                        description={t('admin.ai_accounts.description')}
                    />

                    <div className="max-w-2xl">
                        <AccountForm
                            account={null}
                            capabilities={capabilities}
                            drivers={drivers}
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

Create.layout = {
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
            title: 'New provider account',
            titleKey: 'admin.ai_accounts.new',
            href: create.url(),
        },
    ],
};

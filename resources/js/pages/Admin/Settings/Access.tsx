import { Head } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/admin/settings';
import { SettingCard } from './setting-card';
import type { SettingRow } from './setting-card';

export default function Access({ settings }: { settings: SettingRow[] }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('admin.settings.tabs.access')} />

            <h1 className="sr-only">{t('admin.settings.tabs.access')}</h1>

            {settings.map((setting) => (
                <SettingCard key={setting.key} setting={setting} />
            ))}
        </>
    );
}

Access.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: index.url(),
        },
        {
            title: 'Settings',
            titleKey: 'admin.settings.title',
            href: index.url(),
        },
        {
            title: 'Access & tokens',
            titleKey: 'admin.settings.tabs.access',
            href: show.url('access'),
        },
    ],
};

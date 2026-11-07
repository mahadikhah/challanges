import { Head } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/admin/settings';
import { SettingCard } from './setting-card';
import type { AiCapabilities, SettingRow } from './setting-card';

export default function Ai({
    settings,
    aiCapabilities,
}: {
    settings: SettingRow[];
    aiCapabilities: AiCapabilities;
}) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('admin.settings.tabs.ai')} />

            <h1 className="sr-only">{t('admin.settings.tabs.ai')}</h1>

            {settings.map((setting) => (
                <SettingCard
                    key={setting.key}
                    setting={setting}
                    aiCapabilities={aiCapabilities}
                />
            ))}
        </>
    );
}

Ai.layout = {
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
            title: 'AI approval',
            titleKey: 'admin.settings.tabs.ai',
            href: show.url('ai'),
        },
    ],
};

import { Head, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';

type LabeledEnum = { value: string; label: string };

/**
 * One invite code as the audit sees it — see Admin\InvitesController::rows().
 */
type InviteRow = {
    id: number;
    code: string;
    inviter: string;
    invited: string | null;
    status: LabeledEnum;
    credited_at: string | null;
    created_at: string;
};

export default function Invites({
    invites,
    nextPageUrl,
}: {
    invites: InviteRow[];
    nextPageUrl: string | null;
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('admin.invites.title')} />

            <h1 className="sr-only">{t('admin.invites.title')}</h1>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('admin.invites.title')}
                        description={t('admin.invites.description')}
                    />

                    {invites.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.invites.empty')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.invites.code')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.invites.inviter')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.invites.invited')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.invites.status')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.invites.credited_at')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.invites.created_at')}
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {invites.map((invite) => (
                                        <tr
                                            key={invite.id}
                                            className="border-t"
                                        >
                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 font-mono text-xs"
                                            >
                                                {invite.code}
                                            </td>

                                            <td className="px-3 py-2 font-medium">
                                                {invite.inviter}
                                            </td>

                                            <td className="px-3 py-2">
                                                {invite.invited ?? '—'}
                                            </td>

                                            <td className="px-3 py-2">
                                                <Badge variant="secondary">
                                                    {invite.status.label}
                                                </Badge>
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 whitespace-nowrap text-muted-foreground"
                                            >
                                                {invite.credited_at === null
                                                    ? '—'
                                                    : formatWhen(
                                                          invite.credited_at,
                                                          locale,
                                                      )}
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 whitespace-nowrap text-muted-foreground"
                                            >
                                                {formatWhen(
                                                    invite.created_at,
                                                    locale,
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    {nextPageUrl !== null && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.get(
                                    nextPageUrl,
                                    {},
                                    { preserveState: true },
                                )
                            }
                        >
                            {t('admin.invites.next_page')}
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

function formatWhen(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
    }).format(new Date(iso));
}

Invites.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'Invites',
            href: '/admin/invites',
        },
    ],
};

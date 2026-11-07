import { Head, router } from '@inertiajs/react';
import { ChevronRight, Search } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/users';

/**
 * One account as support sees it — see Admin\UsersController::rows().
 */
type UserRow = {
    id: number;
    name: string;
    platform_user_id: number | null;
    telegram_username: string | null;
    is_admin: boolean;
    balance: number;
};

export default function Users({
    users,
    nextPageUrl,
    q,
}: {
    users: UserRow[];
    nextPageUrl: string | null;
    q: string;
}) {
    const { t } = useTranslation();
    const [search, setSearch] = useState(q);

    const searchFor = (raw: string) => {
        router.get(index.url(), raw === '' ? {} : { q: raw }, {
            preserveState: true,
        });
    };

    return (
        <>
            <Head title={t('admin.users.title')} />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <h1 className="sr-only">{t('admin.users.title')}</h1>

                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('admin.users.title')}
                        description={t('admin.users.description')}
                    />

                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            searchFor(search);
                        }}
                        className="flex items-center justify-end gap-2"
                    >
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t('admin.users.search')}
                            className="h-9 w-64"
                            dir="ltr"
                        />

                        <Button type="submit" size="sm" variant="outline">
                            <Search />
                        </Button>
                    </form>

                    {users.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.users.empty')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.users.user')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.users.platform_user_id')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.users.username')}
                                        </th>
                                        <th className="px-3 py-2 text-end font-medium">
                                            {t('admin.users.balance')}
                                        </th>
                                        <th className="w-12" />
                                    </tr>
                                </thead>

                                <tbody>
                                    {users.map((user) => (
                                        <tr
                                            key={user.id}
                                            className="cursor-pointer border-t hover:bg-muted/30"
                                            onClick={() =>
                                                router.get(
                                                    `/admin/users/${user.id}`,
                                                )
                                            }
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {user.name}
                                                {user.is_admin && (
                                                    <Badge
                                                        variant="outline"
                                                        className="ms-2"
                                                    >
                                                        {t('admin.users.admin')}
                                                    </Badge>
                                                )}
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 text-muted-foreground"
                                            >
                                                {user.platform_user_id ?? '—'}
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 text-muted-foreground"
                                            >
                                                {user.telegram_username
                                                    ? `@${user.telegram_username}`
                                                    : '—'}
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 text-end font-medium"
                                            >
                                                {user.balance}{' '}
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    {t('admin.users.coins')}
                                                </span>
                                            </td>

                                            <td className="px-3 py-2">
                                                <ChevronRight className="ms-auto size-4 text-muted-foreground rtl:rotate-180" />
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
                            {t('admin.users.next_page')}
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

Users.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'Users',
            href: '/admin/users',
        },
    ],
};

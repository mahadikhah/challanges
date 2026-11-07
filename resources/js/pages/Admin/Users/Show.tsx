import { Head, router } from '@inertiajs/react';
import { Minus, Plus, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { coins } from '@/routes/admin/users';

type LabeledEnum = { value: string; label: string };

/**
 * One account in full — see Admin\UsersController::show(). The balance and its
 * drift come from the ledger, never from a column on the user.
 */
type UserView = {
    id: number;
    name: string;
    platform_user_id: number | null;
    telegram_username: string | null;
    locale: string | null;
    is_admin: boolean;
    joined_at: string | null;
};

type LedgerRow = {
    when: string | null;
    reason: LabeledEnum;
    amount: number;
    balance_after: number;
};

export default function UserShow({
    user,
    balance,
    drift,
    transactions,
}: {
    user: UserView;
    balance: number;
    drift: number;
    transactions: LedgerRow[];
}) {
    const { t, locale } = useTranslation();
    const [amount, setAmount] = useState('');

    const adjust = (direction: 'credit' | 'debit') => {
        router.post(
            coins.url(user.id),
            { amount: Number(amount), direction },
            { preserveScroll: true },
        );
    };

    const parsed = Number(amount);

    return (
        <>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Head title={user.name} />

                <h1 className="sr-only">{user.name}</h1>

                <div className="space-y-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {user.name}
                        </h1>

                        {user.is_admin && (
                            <Badge variant="outline">
                                {t('admin.users.admin')}
                            </Badge>
                        )}

                        <Badge variant="secondary" dir="ltr">
                            {balance} {t('admin.users.coins')}
                        </Badge>
                    </div>

                    {drift !== 0 && (
                        <p className="flex items-center gap-2 rounded-md border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                            <TriangleAlert className="size-4 shrink-0" />

                            <span dir="ltr">
                                {t('admin.users.drift')} {drift}{' '}
                                {t('admin.users.drift_coins')}
                            </span>
                        </p>
                    )}

                    <div className="grid gap-4 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {t('admin.users.profile')}
                                </CardTitle>
                            </CardHeader>

                            <CardContent className="space-y-2 text-sm">
                                <Row
                                    label={t('admin.users.platform_user_id')}
                                    value={
                                        user.platform_user_id === null
                                            ? '—'
                                            : String(user.platform_user_id)
                                    }
                                    ltr={user.platform_user_id !== null}
                                />
                                <Row
                                    label={t('admin.users.username')}
                                    value={
                                        user.telegram_username === null
                                            ? '—'
                                            : `@${user.telegram_username}`
                                    }
                                    ltr={user.telegram_username !== null}
                                />
                                <Row
                                    label={t('admin.users.locale')}
                                    value={user.locale ?? '—'}
                                    ltr={user.locale !== null}
                                />
                                <Row
                                    label={t('admin.users.joined_at')}
                                    value={
                                        user.joined_at === null
                                            ? '—'
                                            : formatWhen(user.joined_at, locale)
                                    }
                                    ltr={user.joined_at !== null}
                                />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    {t('admin.users.adjust')}
                                </CardTitle>
                            </CardHeader>

                            <CardContent className="space-y-3">
                                <label className="space-y-2 text-sm" dir="ltr">
                                    <span className="text-muted-foreground">
                                        {t('admin.users.amount_label')}
                                    </span>

                                    <Input
                                        type="number"
                                        min={1}
                                        step={1}
                                        value={amount}
                                        onChange={(event) =>
                                            setAmount(event.target.value)
                                        }
                                    />
                                </label>

                                <div className="flex items-center gap-3">
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={
                                            !Number.isInteger(parsed) ||
                                            parsed < 1
                                        }
                                        onClick={() => adjust('credit')}
                                    >
                                        <Plus />
                                        {t('admin.users.credit')}
                                    </Button>

                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        disabled={
                                            !Number.isInteger(parsed) ||
                                            parsed < 1
                                        }
                                        onClick={() => adjust('debit')}
                                    >
                                        <Minus />
                                        {t('admin.users.debit')}
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.users.ledger')}
                            </CardTitle>
                        </CardHeader>

                        <CardContent>
                            {transactions.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('admin.users.ledger_empty')}
                                </p>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead className="text-muted-foreground">
                                            <tr>
                                                <th className="py-2 text-start font-medium">
                                                    {t('admin.users.when')}
                                                </th>
                                                <th className="py-2 text-start font-medium">
                                                    {t('admin.users.reason')}
                                                </th>
                                                <th className="py-2 text-end font-medium">
                                                    {t('admin.users.amount')}
                                                </th>
                                                <th className="py-2 text-end font-medium">
                                                    {t(
                                                        'admin.users.balance_after',
                                                    )}
                                                </th>
                                            </tr>
                                        </thead>

                                        <tbody>
                                            {transactions.map(
                                                (entry, index) => (
                                                    <tr
                                                        key={index}
                                                        className="border-t"
                                                    >
                                                        <td
                                                            dir="ltr"
                                                            className="py-2 pe-4 whitespace-nowrap text-muted-foreground"
                                                        >
                                                            {formatWhen(
                                                                entry.when,
                                                                locale,
                                                            )}
                                                        </td>

                                                        <td className="py-2 pe-4">
                                                            {entry.reason.label}
                                                        </td>

                                                        <td
                                                            dir="ltr"
                                                            className={`py-2 pe-4 text-end font-medium ${
                                                                entry.amount < 0
                                                                    ? 'text-destructive'
                                                                    : 'text-emerald-600 dark:text-emerald-400'
                                                            }`}
                                                        >
                                                            {entry.amount > 0
                                                                ? `+${entry.amount}`
                                                                : entry.amount}
                                                        </td>

                                                        <td
                                                            dir="ltr"
                                                            className="py-2 text-end text-muted-foreground"
                                                        >
                                                            {
                                                                entry.balance_after
                                                            }
                                                        </td>
                                                    </tr>
                                                ),
                                            )}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function Row({
    label,
    value,
    ltr = false,
}: {
    label: string;
    value: string;
    ltr?: boolean;
}) {
    return (
        <div className="flex items-start justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-end" dir={ltr ? 'ltr' : undefined}>
                {value}
            </span>
        </div>
    );
}

function formatWhen(iso: string | null, locale: string): string {
    if (iso === null) {
        return '';
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

UserShow.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'Users',
            href: '/admin/users',
        },
        {
            title: 'User',
            href: '/admin/users',
        },
    ],
};

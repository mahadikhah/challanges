import { Head, router } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { refund } from '@/routes/admin/payments';

type LabeledEnum = { value: string; label: string };

/**
 * One Stars purchase as the audit sees it — see Admin\PaymentsController::rows().
 */
type PaymentRow = {
    id: number;
    user: string;
    telegram_payment_charge_id: string | null;
    stars_amount: number;
    coin_amount: number;
    status: LabeledEnum;
    refundable: boolean;
    paid_at: string | null;
    refunded_at: string | null;
    created_at: string;
};

export default function Payments({
    payments,
    nextPageUrl,
}: {
    payments: PaymentRow[];
    nextPageUrl: string | null;
}) {
    const { t, locale } = useTranslation();

    const refundPayment = (id: number) => {
        if (!window.confirm(t('admin.payments.refund_confirm'))) {
            return;
        }

        router.post(refund.url(id), {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title={t('admin.payments.title')} />

            <h1 className="sr-only">{t('admin.payments.title')}</h1>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('admin.payments.title')}
                        description={t('admin.payments.description')}
                    />

                    {payments.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('admin.payments.empty')}
                        </p>
                    ) : (
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50">
                                    <tr>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.payments.user')}
                                        </th>
                                        <th className="px-3 py-2 text-center font-medium">
                                            {t('admin.payments.stars')}
                                        </th>
                                        <th className="px-3 py-2 text-center font-medium">
                                            {t('admin.payments.coins')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.payments.status')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.payments.charge')}
                                        </th>
                                        <th className="px-3 py-2 text-start font-medium">
                                            {t('admin.payments.created_at')}
                                        </th>
                                        <th className="w-12" />
                                    </tr>
                                </thead>

                                <tbody>
                                    {payments.map((payment) => (
                                        <tr
                                            key={payment.id}
                                            className="border-t"
                                        >
                                            <td className="px-3 py-2 font-medium">
                                                {payment.user}
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 text-center"
                                            >
                                                {payment.stars_amount}
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 text-center"
                                            >
                                                {payment.coin_amount}
                                            </td>

                                            <td className="px-3 py-2">
                                                <Badge variant="secondary">
                                                    {payment.status.label}
                                                </Badge>
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="max-w-40 truncate px-3 py-2 text-xs text-muted-foreground"
                                                title={
                                                    payment.telegram_payment_charge_id ??
                                                    '—'
                                                }
                                            >
                                                {payment.telegram_payment_charge_id ??
                                                    '—'}
                                            </td>

                                            <td
                                                dir="ltr"
                                                className="px-3 py-2 whitespace-nowrap text-muted-foreground"
                                            >
                                                {formatWhen(payment, locale)}
                                            </td>

                                            <td className="px-3 py-2">
                                                {payment.refundable && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() =>
                                                            refundPayment(
                                                                payment.id,
                                                            )
                                                        }
                                                        title={t(
                                                            'admin.payments.refund',
                                                        )}
                                                    >
                                                        <Undo2 />
                                                        {t(
                                                            'admin.payments.refund',
                                                        )}
                                                    </Button>
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
                            {t('admin.payments.next_page')}
                        </Button>
                    )}
                </div>
            </div>
        </>
    );
}

/**
 * The most meaningful clock on the row: when it was refunded, then when it was
 * paid, then when the invoice was created.
 */
function formatWhen(payment: PaymentRow, locale: string): string {
    const iso = payment.refunded_at ?? payment.paid_at ?? payment.created_at;

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

Payments.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'Star payments',
            href: '/admin/payments',
        },
    ],
};

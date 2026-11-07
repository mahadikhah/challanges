import { Head } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/ai-usage';
import { index as settingsIndex } from '@/routes/admin/settings';

type TokenTotals = {
    input_tokens: number;
    output_tokens: number;
    total_tokens: number;
};

type AccountUsage = {
    id: number;
    name: string;
    driver: string | null;
    is_active: boolean;
    usage: TokenTotals;
};

type RecentRecord = {
    operation: string;
    outcome: string;
    driver: string;
    model: string;
    input_tokens: number;
    output_tokens: number;
    total_tokens: number;
    estimated_cost_minor: number;
    created_at: string | null;
};

const WINDOWS = ['today', '7d', '30d'] as const;

export default function AiUsage({
    window: activeWindow,
    totals,
    currency,
    costMinor,
    perAccount,
    recent,
}: {
    window: (typeof WINDOWS)[number];
    totals: TokenTotals;
    currency: { code: string; minor_unit: number };
    costMinor: number;
    perAccount: AccountUsage[];
    recent: RecentRecord[];
}) {
    const { t, locale } = useTranslation();

    const cost = new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currency.code,
        maximumFractionDigits: 2,
    }).format(costMinor / currency.minor_unit);

    const number = (value: number): string =>
        new Intl.NumberFormat(locale).format(value);

    const chartData = perAccount.map((account) => ({
        name: account.name,
        input: account.usage.input_tokens,
        output: account.usage.output_tokens,
    }));

    return (
        <>
            <Head title={t('admin.ai_usage.title')} />

            <h1 className="sr-only">{t('admin.ai_usage.title')}</h1>

            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-6">
                    <div className="flex flex-wrap items-end justify-between gap-4">
                        <Heading
                            variant="small"
                            title={t('admin.ai_usage.title')}
                            description={t('admin.ai_usage.description')}
                        />

                        <div
                            className="flex gap-1 rounded-lg bg-muted p-1"
                            role="tablist"
                        >
                            {WINDOWS.map((option) => (
                                <Link
                                    key={option}
                                    href={index.url({
                                        query: { window: option },
                                    })}
                                    className={cn(
                                        'rounded-md px-3 py-1 text-sm transition-colors',
                                        option === activeWindow
                                            ? 'bg-background font-medium shadow-sm'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {t(`admin.ai_usage.windows.${option}`)}
                                </Link>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-4">
                        <StatTile
                            label={t('admin.ai_usage.total_tokens')}
                            value={number(totals.total_tokens)}
                        />
                        <StatTile
                            label={t('admin.ai_usage.input_tokens')}
                            value={number(totals.input_tokens)}
                        />
                        <StatTile
                            label={t('admin.ai_usage.output_tokens')}
                            value={number(totals.output_tokens)}
                        />
                        <StatTile
                            label={t('admin.ai_usage.cost')}
                            value={cost}
                        />
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.ai_usage.per_account')}
                            </CardTitle>
                        </CardHeader>

                        <CardContent>
                            {perAccount.every(
                                (account) => account.usage.total_tokens === 0,
                            ) ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('admin.ai_usage.chart_empty')}
                                </p>
                            ) : (
                                // The chart itself stays LTR: its axes are
                                // numbers and driver names, and Recharts
                                // does not mirror its own layout.
                                <div className="h-64 w-full" dir="ltr">
                                    <ResponsiveContainer>
                                        <BarChart data={chartData}>
                                            <CartesianGrid
                                                strokeDasharray="3 3"
                                                vertical={false}
                                                stroke="var(--border)"
                                            />
                                            <XAxis
                                                dataKey="name"
                                                tickLine={false}
                                                axisLine={false}
                                                fontSize={12}
                                            />
                                            <YAxis
                                                tickLine={false}
                                                axisLine={false}
                                                fontSize={12}
                                                width={48}
                                            />
                                            <Tooltip
                                                cursor={{
                                                    fill: 'var(--muted)',
                                                }}
                                                contentStyle={{
                                                    backgroundColor:
                                                        'var(--popover)',
                                                    border: '1px solid var(--border)',
                                                    borderRadius: 8,
                                                    color: 'var(--popover-foreground)',
                                                }}
                                            />
                                            <Bar
                                                dataKey="input"
                                                name={t(
                                                    'admin.ai_usage.input_tokens',
                                                )}
                                                stackId="tokens"
                                                fill="var(--chart-1)"
                                                radius={[0, 0, 0, 0]}
                                            />
                                            <Bar
                                                dataKey="output"
                                                name={t(
                                                    'admin.ai_usage.output_tokens',
                                                )}
                                                stackId="tokens"
                                                fill="var(--chart-2)"
                                                radius={[4, 4, 0, 0]}
                                            />
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.ai_usage.recent')}
                            </CardTitle>
                            <p className="text-sm text-muted-foreground">
                                {t('admin.ai_usage.recent_description')}
                            </p>
                        </CardHeader>

                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50">
                                        <tr>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t('admin.ai_usage.operation')}
                                            </th>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t('admin.ai_usage.outcome')}
                                            </th>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t('admin.ai_usage.driver')}
                                            </th>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t(
                                                    'admin.ai_usage.total_tokens',
                                                )}
                                            </th>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t('admin.ai_usage.cost')}
                                            </th>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t('admin.ai_usage.when')}
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {recent.map((record, index) => (
                                            <tr
                                                key={index}
                                                className="border-t"
                                            >
                                                <td
                                                    dir="ltr"
                                                    className="px-3 py-2 font-mono text-xs"
                                                >
                                                    {record.operation}
                                                </td>

                                                <td className="px-3 py-2">
                                                    <Badge variant="secondary">
                                                        {t(
                                                            `admin.ai_usage.outcomes.${record.outcome}`,
                                                        )}
                                                    </Badge>
                                                </td>

                                                <td
                                                    dir="ltr"
                                                    className="px-3 py-2 text-muted-foreground"
                                                >
                                                    {record.driver} ·{' '}
                                                    {record.model ?? '—'}
                                                </td>

                                                <td
                                                    dir="ltr"
                                                    className="px-3 py-2"
                                                >
                                                    {number(
                                                        record.total_tokens,
                                                    )}
                                                </td>

                                                <td
                                                    dir="ltr"
                                                    className="px-3 py-2 text-muted-foreground"
                                                >
                                                    {record.estimated_cost_minor >
                                                    0
                                                        ? new Intl.NumberFormat(
                                                              locale,
                                                              {
                                                                  style: 'currency',
                                                                  currency:
                                                                      currency.code,
                                                                  maximumFractionDigits: 2,
                                                              },
                                                          ).format(
                                                              record.estimated_cost_minor /
                                                                  currency.minor_unit,
                                                          )
                                                        : '—'}
                                                </td>

                                                <td
                                                    dir="ltr"
                                                    className="px-3 py-2 whitespace-nowrap text-muted-foreground"
                                                >
                                                    {record.created_at === null
                                                        ? '—'
                                                        : new Intl.DateTimeFormat(
                                                              locale,
                                                              {
                                                                  dateStyle:
                                                                      'short',
                                                                  timeStyle:
                                                                      'short',
                                                              },
                                                          ).format(
                                                              new Date(
                                                                  record.created_at,
                                                              ),
                                                          )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function StatTile({ label, value }: { label: string; value: string }) {
    return (
        <Card>
            <CardContent className="space-y-1">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p className="text-2xl font-semibold tracking-tight" dir="ltr">
                    {value}
                </p>
            </CardContent>
        </Card>
    );
}

AiUsage.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: settingsIndex.url(),
        },
        {
            title: 'AI usage',
            titleKey: 'admin.ai_usage.title',
            href: index.url(),
        },
    ],
};

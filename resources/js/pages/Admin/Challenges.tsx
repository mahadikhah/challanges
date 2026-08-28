import { Head, router } from '@inertiajs/react';
import { ChevronRight, Search } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/admin/challenges';

type LabeledEnum = { value: string; label: string };

/**
 * One moderation row, as the server states it — see
 * Admin\ChallengesController::rows().
 */
type ChallengeRow = {
    id: number;
    title: string;
    status: LabeledEnum;
    proof_type: LabeledEnum;
    period_type: LabeledEnum;
    creator: string;
    participants_count: number;
    total_periods: number;
    starts_at: string;
};

const STATUS_FILTERS = ['active', 'scheduled', 'completed', 'cancelled'];

export default function Challenges({
    challenges,
    nextPageUrl,
    filters,
}: {
    challenges: ChallengeRow[];
    nextPageUrl: string | null;
    filters: { status: string | null; q: string };
}) {
    const { t, locale } = useTranslation();
    const [search, setSearch] = useState(filters.q);

    const filterBy = (status: string | null) => {
        router.get(
            index.url(),
            {
                ...(status === null ? {} : { status }),
                ...(filters.q === '' ? {} : { q: filters.q }),
            },
            { preserveState: true },
        );
    };

    const searchFor = (raw: string) => {
        router.get(
            index.url(),
            {
                ...(filters.status === null ? {} : { status: filters.status }),
                ...(raw === '' ? {} : { q: raw }),
            },
            { preserveState: true },
        );
    };

    return (
        <>
            <Head title={t('admin.challenges.title')} />

            <h1 className="sr-only">{t('admin.challenges.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('admin.challenges.title')}
                    description={t('admin.challenges.description')}
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant={
                                filters.status === null ? 'default' : 'outline'
                            }
                            size="sm"
                            onClick={() => filterBy(null)}
                        >
                            {t('admin.challenges.filter.all')}
                        </Button>

                        {STATUS_FILTERS.map((status) => (
                            <Button
                                key={status}
                                type="button"
                                variant={
                                    filters.status === status
                                        ? 'default'
                                        : 'outline'
                                }
                                size="sm"
                                onClick={() => filterBy(status)}
                            >
                                {t(`enums.challenge_status.${status}`)}
                            </Button>
                        ))}
                    </div>

                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            searchFor(search);
                        }}
                        className="flex items-center gap-2"
                    >
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder={t('admin.challenges.search')}
                            className="h-9 w-48"
                        />

                        <Button type="submit" size="sm" variant="outline">
                            <Search />
                        </Button>
                    </form>
                </div>

                {challenges.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.challenges.empty')}
                    </p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50">
                                <tr>
                                    <th className="px-3 py-2 text-start font-medium">
                                        {t('admin.challenges.challenge')}
                                    </th>
                                    <th className="px-3 py-2 text-start font-medium">
                                        {t('admin.challenges.status')}
                                    </th>
                                    <th className="px-3 py-2 text-start font-medium">
                                        {t('admin.challenges.creator')}
                                    </th>
                                    <th className="px-3 py-2 text-center font-medium">
                                        {t('admin.challenges.participants')}
                                    </th>
                                    <th className="px-3 py-2 text-center font-medium">
                                        {t('admin.challenges.periods')}
                                    </th>
                                    <th className="px-3 py-2 text-start font-medium">
                                        {t('admin.challenges.starts_at')}
                                    </th>
                                    <th className="w-12" />
                                </tr>
                            </thead>

                            <tbody>
                                {challenges.map((challenge) => (
                                    <tr
                                        key={challenge.id}
                                        className="cursor-pointer border-t hover:bg-muted/30"
                                        onClick={() =>
                                            router.get(
                                                `/admin/challenges/${challenge.id}`,
                                            )
                                        }
                                    >
                                        <td className="px-3 py-2 font-medium">
                                            {challenge.title}
                                            <span className="block text-xs text-muted-foreground">
                                                {challenge.period_type.label} ·{' '}
                                                {challenge.proof_type.label}
                                            </span>
                                        </td>

                                        <td className="px-3 py-2">
                                            <Badge variant="secondary">
                                                {challenge.status.label}
                                            </Badge>
                                        </td>

                                        <td className="px-3 py-2">
                                            {challenge.creator}
                                        </td>

                                        <td className="px-3 py-2 text-center">
                                            {challenge.participants_count}
                                        </td>

                                        <td className="px-3 py-2 text-center">
                                            {challenge.total_periods}
                                        </td>

                                        <td
                                            dir="ltr"
                                            className="px-3 py-2 whitespace-nowrap"
                                        >
                                            {formatWhen(
                                                challenge.starts_at,
                                                locale,
                                            )}
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
                            router.get(nextPageUrl, {}, { preserveState: true })
                        }
                    >
                        {t('admin.challenges.next_page')}
                    </Button>
                )}
            </div>
        </>
    );
}

function formatWhen(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(
        new Date(iso),
    );
}

Challenges.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'Challenges',
            href: '/admin/challenges',
        },
    ],
};

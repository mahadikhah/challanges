import { Head, router } from '@inertiajs/react';
import { Activity, HeartPulse } from 'lucide-react';
import { useEffect } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import {
    discard as discardFailedJob,
    retry as retryFailedJob,
} from '@/routes/admin/system-health/failed-jobs';

/**
 * The shape Admin\SystemHealthController::index() props carry — see
 * SystemHealthSnapshot::build() for where each number comes from.
 */
type Health = {
    scheduler: {
        last_ran_at: string | null;
        stale_after_minutes: number;
        healthy: boolean;
    };
    queue: {
        pending_count: number;
        oldest_pending_minutes: number | null;
        failed_count: number;
    };
    exceptions: {
        available: boolean;
        rows: { class: string; count: number; last_seen_at: string }[];
    };
    providers: {
        provider: string;
        label: string;
        success: number;
        failure: number;
    }[];
};

type FailedJob = {
    uuid: string;
    queue: string;
    name: string;
    exception: string;
    failed_at: string;
};

export default function SystemHealth({
    health,
    failedJobs,
}: {
    health: Health;
    failedJobs: FailedJob[];
}) {
    const { t, locale } = useTranslation();

    // A glanceable page stays glanceable: the numbers refresh in place
    // without a reload, on a slow-enough tick that nobody watches numbers
    // twitch.
    useEffect(() => {
        const timer = setInterval(() => {
            router.reload({ only: ['health', 'failedJobs'] });
        }, 30_000);

        return () => clearInterval(timer);
    }, []);

    return (
        <>
            <Head title={t('admin.system_health.title')} />

            <h1 className="sr-only">{t('admin.system_health.title')}</h1>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="space-y-6">
                    <Heading
                        variant="small"
                        title={t('admin.system_health.title')}
                        description={t('admin.system_health.description')}
                    />

                    <div className="grid gap-4 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <HeartPulse className="size-4" />
                                    {t('admin.system_health.scheduler')}
                                </CardTitle>
                                <CardDescription>
                                    {t(
                                        'admin.system_health.scheduler_description',
                                        {
                                            minutes:
                                                health.scheduler
                                                    .stale_after_minutes,
                                        },
                                    )}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <Badge
                                    variant={
                                        health.scheduler.healthy
                                            ? 'secondary'
                                            : 'destructive'
                                    }
                                >
                                    {health.scheduler.healthy
                                        ? t('admin.system_health.healthy')
                                        : t('admin.system_health.stale')}
                                </Badge>
                                <p dir="ltr" className="text-muted-foreground">
                                    {health.scheduler.last_ran_at === null
                                        ? t('admin.system_health.never_ran')
                                        : formatWhen(
                                              health.scheduler.last_ran_at,
                                              locale,
                                          )}
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Activity className="size-4" />
                                    {t('admin.system_health.queue')}
                                </CardTitle>
                                <CardDescription>
                                    {t('admin.system_health.queue_description')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-2 text-sm">
                                <p>
                                    {t('admin.system_health.pending', {
                                        count: health.queue.pending_count,
                                    })}
                                </p>
                                <p className="text-muted-foreground">
                                    {t('admin.system_health.oldest_pending', {
                                        minutes:
                                            health.queue
                                                .oldest_pending_minutes ?? '—',
                                    })}
                                </p>
                                <p>
                                    {t('admin.system_health.failed', {
                                        count: health.queue.failed_count,
                                    })}
                                </p>
                            </CardContent>
                        </Card>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.system_health.providers')}
                            </CardTitle>
                            <CardDescription>
                                {t('admin.system_health.providers_description')}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="overflow-x-auto rounded-md border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50">
                                        <tr>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t(
                                                    'admin.system_health.provider',
                                                )}
                                            </th>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t(
                                                    'admin.system_health.success',
                                                )}
                                            </th>
                                            <th className="px-3 py-2 text-start font-medium">
                                                {t(
                                                    'admin.system_health.failure',
                                                )}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {health.providers.map((provider) => (
                                            <tr
                                                key={provider.provider}
                                                className="border-t"
                                            >
                                                <td className="px-3 py-2 font-medium">
                                                    {provider.label}
                                                </td>
                                                <td
                                                    dir="ltr"
                                                    className="px-3 py-2"
                                                >
                                                    {provider.success}
                                                </td>
                                                <td
                                                    dir="ltr"
                                                    className="px-3 py-2"
                                                >
                                                    {provider.failure}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.system_health.exceptions')}
                            </CardTitle>
                            <CardDescription>
                                {t(
                                    'admin.system_health.exceptions_description',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {!health.exceptions.available ? (
                                <p className="text-muted-foreground">
                                    {t(
                                        'admin.system_health.exceptions_unavailable',
                                    )}
                                </p>
                            ) : health.exceptions.rows.length === 0 ? (
                                <p className="text-muted-foreground">
                                    {t('admin.system_health.exceptions_empty')}
                                </p>
                            ) : (
                                <div className="overflow-x-auto rounded-md border">
                                    <table className="w-full text-sm">
                                        <thead className="bg-muted/50">
                                            <tr>
                                                <th className="px-3 py-2 text-start font-medium">
                                                    {t(
                                                        'admin.system_health.exception_class',
                                                    )}
                                                </th>
                                                <th className="px-3 py-2 text-start font-medium">
                                                    {t(
                                                        'admin.system_health.count',
                                                    )}
                                                </th>
                                                <th className="px-3 py-2 text-start font-medium">
                                                    {t(
                                                        'admin.system_health.last_seen',
                                                    )}
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {health.exceptions.rows.map(
                                                (row) => (
                                                    <tr
                                                        key={row.class}
                                                        className="border-t"
                                                    >
                                                        <td
                                                            dir="ltr"
                                                            className="px-3 py-2 font-mono text-xs"
                                                        >
                                                            {row.class}
                                                        </td>
                                                        <td
                                                            dir="ltr"
                                                            className="px-3 py-2"
                                                        >
                                                            {row.count}
                                                        </td>
                                                        <td
                                                            dir="ltr"
                                                            className="px-3 py-2 text-muted-foreground"
                                                        >
                                                            {formatWhen(
                                                                row.last_seen_at,
                                                                locale,
                                                            )}
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

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.system_health.failed_jobs')}
                            </CardTitle>
                            <CardDescription>
                                {t(
                                    'admin.system_health.failed_jobs_description',
                                )}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            {failedJobs.length === 0 ? (
                                <p className="text-muted-foreground">
                                    {t('admin.system_health.no_failed_jobs')}
                                </p>
                            ) : (
                                failedJobs.map((job) => (
                                    <div
                                        key={job.uuid}
                                        className="space-y-1 rounded-md border p-3"
                                    >
                                        <p
                                            dir="ltr"
                                            className="font-mono text-xs"
                                        >
                                            {job.name}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {job.exception}
                                        </p>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        retryFailedJob.url(
                                                            job.uuid,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t('admin.system_health.retry')}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.post(
                                                        discardFailedJob.url(
                                                            job.uuid,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                {t(
                                                    'admin.system_health.discard',
                                                )}
                                            </Button>
                                        </div>
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <div className="flex flex-wrap gap-2">
                        {/* Telescope keeps its own auth (the is_admin gate);
                            opening it beside this page is the "dig deeper"
                            path. */}
                        <a href="/telescope" target="_blank" rel="noopener">
                            <Button type="button" variant="outline" size="sm">
                                {t('admin.system_health.open_telescope')}
                            </Button>
                        </a>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => router.reload()}
                        >
                            {t('admin.system_health.refresh')}
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}

function formatWhen(raw: string, locale: string): string {
    const when = new Date(
        raw.includes('T') ? raw : `${raw.replace(' ', 'T')}Z`,
    );

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(when);
}

SystemHealth.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'System health',
            href: '/admin/system-health',
        },
    ],
};

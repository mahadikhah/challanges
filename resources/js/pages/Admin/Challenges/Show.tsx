import { Head, router } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';

type LabeledEnum = { value: string; label: string };

/**
 * One challenge as moderation sees it — see
 * Admin\ChallengesController::show().
 */
type ChallengeView = {
    id: number;
    title: string;
    description: string;
    status: LabeledEnum;
    visibility: LabeledEnum;
    proof_type: LabeledEnum;
    period_type: LabeledEnum;
    creator: string;
    starts_at: string;
    total_periods: number;
    timezone: string;
    default_freezes: number;
    announced_at: string | null;
    cancellable: boolean;
};

type ParticipantRow = {
    name: string;
    status: LabeledEnum;
    streak: number;
    longest_streak: number;
    freezes_used: number;
    freezes_total: number;
    joined_at: string;
};

export default function ChallengeShow({
    challenge,
    participants,
}: {
    challenge: ChallengeView;
    participants: ParticipantRow[];
}) {
    const { t, locale } = useTranslation();

    const cancel = () => {
        if (!window.confirm(t('admin.challenges.cancel_confirm'))) {
            return;
        }

        router.post(`/admin/challenges/${challenge.id}/cancel`, {}, {});
    };

    return (
        <>
            <Head title={challenge.title} />

            <h1 className="sr-only">{challenge.title}</h1>

            <div className="space-y-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight">
                                {challenge.title}
                            </h1>

                            <Badge variant="secondary">
                                {challenge.status.label}
                            </Badge>

                            <Badge variant="outline">
                                {challenge.visibility.label}
                            </Badge>
                        </div>

                        <p className="text-sm text-muted-foreground">
                            {t('admin.challenges.creator')}: {challenge.creator}
                        </p>
                    </div>

                    {challenge.cancellable && (
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            onClick={cancel}
                        >
                            <Ban />
                            {t('admin.challenges.cancel')}
                        </Button>
                    )}
                </div>

                <div className="grid gap-4 md:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.challenges.challenge')}
                            </CardTitle>
                        </CardHeader>

                        <CardContent className="space-y-2 text-sm">
                            <Row
                                label={t('admin.challenges.description_label')}
                                value={challenge.description}
                            />
                            <Row
                                label={t('admin.challenges.cadence')}
                                value={challenge.period_type.label}
                            />
                            <Row
                                label={t('admin.challenges.periods')}
                                value={String(challenge.total_periods)}
                            />
                            <Row
                                label={t('admin.challenges.starts_at')}
                                value={formatWhen(challenge.starts_at, locale)}
                                ltr
                            />
                            <Row
                                label={t('admin.challenges.timezone')}
                                value={challenge.timezone}
                                ltr
                            />
                            <Row
                                label={t('admin.challenges.proof')}
                                value={challenge.proof_type.label}
                            />
                            <Row
                                label={t('admin.challenges.default_freezes')}
                                value={String(challenge.default_freezes)}
                            />
                            <Row
                                label={t('admin.challenges.announced_at')}
                                value={
                                    challenge.announced_at === null
                                        ? '—'
                                        : formatWhen(
                                              challenge.announced_at,
                                              locale,
                                          )
                                }
                                ltr={challenge.announced_at !== null}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">
                                {t('admin.challenges.participants')} (
                                {participants.length})
                            </CardTitle>
                        </CardHeader>

                        <CardContent>
                            {participants.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('admin.challenges.empty')}
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {participants.map((participant, index) => (
                                        <div
                                            key={index}
                                            className="flex flex-wrap items-center justify-between gap-2 border-b pb-2 text-sm last:border-b-0 last:pb-0"
                                        >
                                            <div>
                                                <p className="font-medium">
                                                    {participant.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {participant.status.label} ·{' '}
                                                    {t(
                                                        'admin.challenges.joined_at',
                                                    )}
                                                    :{' '}
                                                    {formatWhen(
                                                        participant.joined_at,
                                                        locale,
                                                    )}
                                                </p>
                                            </div>

                                            <div className="flex items-center gap-4 text-xs text-muted-foreground">
                                                <span>
                                                    {t(
                                                        'admin.challenges.streak',
                                                    )}
                                                    :{' '}
                                                    <span className="font-medium text-foreground">
                                                        {participant.streak}
                                                    </span>{' '}
                                                    (
                                                    {t('admin.challenges.best')}{' '}
                                                    {participant.longest_streak}
                                                    )
                                                </span>

                                                <span dir="ltr">
                                                    {participant.freezes_used}/
                                                    {participant.freezes_total}{' '}
                                                    {t(
                                                        'admin.challenges.freezes',
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
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

function formatWhen(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

ChallengeShow.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'Challenges',
            href: '/admin/challenges',
        },
        {
            title: 'Challenge',
            href: '/admin/challenges',
        },
    ],
};

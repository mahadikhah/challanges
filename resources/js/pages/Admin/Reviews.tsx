import { Head, router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { approve, reject } from '@/routes/admin/reviews';

/**
 * One photo waiting on a verdict, as the server states it — see
 * Admin\ReviewQueueController::index().
 */
type ReviewRow = {
    id: number;
    challenge: string;
    participant: string;
    period: number;
    total_periods: number;
    submitted_at: string | null;
    proof_url: string;
};

export default function Reviews({ reviews }: { reviews: ReviewRow[] }) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('admin.reviews.title')} />

            <h1 className="sr-only">{t('admin.reviews.title')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('admin.reviews.title')}
                    description={t('admin.reviews.description')}
                />

                {reviews.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('admin.reviews.empty')}
                    </p>
                )}

                <div className="grid gap-4 lg:grid-cols-2">
                    {reviews.map((review) => (
                        <ReviewCard
                            key={review.id}
                            review={review}
                            locale={locale}
                            onApprove={() => {
                                router.post(
                                    approve.url(review.id),
                                    {},
                                    {
                                        preserveScroll: true,
                                    },
                                );
                            }}
                            onReject={() => {
                                router.post(
                                    reject.url(review.id),
                                    {},
                                    {
                                        preserveScroll: true,
                                    },
                                );
                            }}
                        />
                    ))}
                </div>
            </div>
        </>
    );
}

function ReviewCard({
    review,
    locale,
    onApprove,
    onReject,
}: {
    review: ReviewRow;
    locale: string;
    onApprove: () => void;
    onReject: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Card>
            <CardContent className="space-y-4 pt-6">
                <div className="space-y-1">
                    <div className="flex items-center justify-between gap-3">
                        <p className="font-medium">{review.challenge}</p>

                        <Badge variant="secondary">
                            {t('admin.reviews.period')} {review.period}/
                            {review.total_periods}
                        </Badge>
                    </div>

                    <p className="text-sm text-muted-foreground">
                        {review.participant} ·{' '}
                        {formatWhen(review.submitted_at, locale)}
                    </p>
                </div>

                <a href={review.proof_url} target="_blank" rel="noopener">
                    <img
                        src={review.proof_url}
                        alt={t('admin.reviews.proof')}
                        loading="lazy"
                        className="max-h-64 w-full rounded-md border object-contain"
                    />
                </a>

                <div className="flex items-center gap-3">
                    <Button
                        type="button"
                        size="sm"
                        onClick={onApprove}
                        title={t('admin.reviews.approve')}
                    >
                        <Check />
                        {t('admin.reviews.approve')}
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={onReject}
                        title={t('admin.reviews.reject')}
                    >
                        <X />
                        {t('admin.reviews.reject')}
                    </Button>
                </div>
            </CardContent>
        </Card>
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

Reviews.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: '/admin/settings',
        },
        {
            title: 'Proof review',
            href: '/admin/reviews',
        },
    ],
};

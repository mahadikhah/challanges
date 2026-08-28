import { Head, router } from '@inertiajs/react';
import { Check, Sparkles, X } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { approve, override, reject } from '@/routes/admin/reviews';

/**
 * One photo in the review surface, as the server states it — see
 * `Admin\ReviewQueueController::index()`. `status` distinguishes the two
 * lists: pending rows are always `submitted`; AI-settled rows carry the
 * verdict that already took.
 */
type ReviewRow = {
    id: number;
    challenge: string;
    participant: string;
    period: number;
    total_periods: number;
    submitted_at: string | null;
    proof_url: string;
    ai_decision: AiDecision | null;
    status: string;
};

/**
 * What the latest AI moderation call said, display data only — the reason is
 * shown to the admin and read by nothing else.
 */
type AiDecision = {
    approved: boolean | null;
    confidence: number | null;
    reason: string | null;
    model: string | null;
    fell_back: boolean;
};

export default function Reviews({
    reviews,
    settled,
}: {
    reviews: ReviewRow[];
    settled: ReviewRow[];
}) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('admin.reviews.title')} />

            <h1 className="sr-only">{t('admin.reviews.title')}</h1>
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
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

                    {settled.length > 0 && (
                        <section className="space-y-4">
                            <Heading
                                variant="small"
                                title={t('admin.reviews.settled.title')}
                                description={t(
                                    'admin.reviews.settled.description',
                                )}
                            />

                            <div className="grid gap-4 lg:grid-cols-2">
                                {settled.map((review) => (
                                    <ReviewCard
                                        key={review.id}
                                        review={review}
                                        locale={locale}
                                        onApprove={() => {
                                            router.post(
                                                override.url([
                                                    review.id,
                                                    'approve',
                                                ]),
                                                {},
                                                { preserveScroll: true },
                                            );
                                        }}
                                        onReject={() => {
                                            router.post(
                                                override.url([
                                                    review.id,
                                                    'reject',
                                                ]),
                                                {},
                                                { preserveScroll: true },
                                            );
                                        }}
                                    />
                                ))}
                            </div>
                        </section>
                    )}
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

    // Pending rows decide; AI-settled rows overturn a decision already in
    // force — same two endpoints' shape, different words on the buttons.
    const pending = review.status === 'submitted';
    const approveLabel = pending
        ? t('admin.reviews.approve')
        : t('admin.reviews.settled.overturn_approve');
    const rejectLabel = pending
        ? t('admin.reviews.reject')
        : t('admin.reviews.settled.overturn_reject');

    return (
        <Card>
            <CardContent className="space-y-4 pt-6">
                <div className="space-y-1">
                    <div className="flex items-center justify-between gap-3">
                        <p className="font-medium">{review.challenge}</p>

                        <div className="flex items-center gap-2">
                            {!pending && (
                                <Badge variant="outline">
                                    {review.status === 'approved'
                                        ? t('admin.reviews.settled.approved')
                                        : t('admin.reviews.settled.rejected')}
                                </Badge>
                            )}

                            <Badge variant="secondary">
                                {t('admin.reviews.period')} {review.period}/
                                {review.total_periods}
                            </Badge>
                        </div>
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

                {review.ai_decision !== null && (
                    <AiDecisionNote decision={review.ai_decision} />
                )}

                <div className="flex items-center gap-3">
                    <Button
                        type="button"
                        size="sm"
                        onClick={onApprove}
                        title={approveLabel}
                    >
                        <Check />
                        {approveLabel}
                    </Button>

                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={onReject}
                        title={rejectLabel}
                    >
                        <X />
                        {rejectLabel}
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

/**
 * The AI's say on a photo that still reached the human queue — either it
 * hedged below the threshold or no provider answered. The admin sees what it
 * thought before deciding themselves.
 */
function AiDecisionNote({ decision }: { decision: AiDecision }) {
    const { t } = useTranslation();

    const verdict =
        decision.approved === null
            ? t('admin.reviews.ai.no_answer')
            : decision.approved
              ? t('admin.reviews.ai.approve')
              : t('admin.reviews.ai.reject');

    return (
        <div className="space-y-1 rounded-md border border-dashed p-3 text-sm">
            <div className="flex items-center gap-2">
                <Sparkles className="size-4 text-muted-foreground" />
                <span className="font-medium">{verdict}</span>

                {decision.confidence !== null && (
                    <Badge variant="outline">
                        {t('admin.reviews.ai.confidence', {
                            confidence: decision.confidence,
                        })}
                    </Badge>
                )}

                {decision.fell_back && (
                    <Badge variant="secondary">
                        {t('admin.reviews.ai.fell_back')}
                    </Badge>
                )}
            </div>

            {decision.reason !== null && (
                <p className="text-muted-foreground">{decision.reason}</p>
            )}
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

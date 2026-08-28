import { useCallback, useEffect, useState } from 'react';

import { ApiError, submitCheckIn } from '../api';
import { localization, t } from '../localization';
import { webApp } from '../telegram';
import type { ChallengeView } from '../types';

/**
 * One challenge as the participant's dashboard: the numbers (streak, best,
 * freezes), the period open right now with its verdict, progress through the
 * whole timeline, and the history grid behind it.
 *
 * The one-tap check-in is the Telegram MainButton while — and only while —
 * the current period is owed and the challenge's proof is a tap. Every other
 * proof type keeps its path in the bot, and the card says so instead of
 * offering a button that could not work.
 */

type Notice =
    | { kind: 'success' }
    | { kind: 'error'; text: string }
    | { kind: 'gate'; text: string; joinUrl: string | null };

export function ChallengeDetail({ initial }: { initial: ChallengeView }) {
    const [challenge, setChallenge] = useState(initial);
    const [pending, setPending] = useState(false);
    const [notice, setNotice] = useState<Notice | null>(null);

    const period = challenge.current_period;
    const canTap =
        period !== null &&
        period.owes_check_in &&
        challenge.proof_type.value === 'button';

    const tap = useCallback(async () => {
        if (!canTap || pending) {
            return;
        }

        setPending(true);
        setNotice(null);

        try {
            const updated = await submitCheckIn(challenge.id);

            setChallenge(updated);
            setNotice({ kind: 'success' });
            webApp()?.HapticFeedback?.notificationOccurred('success');
        } catch (error) {
            webApp()?.HapticFeedback?.notificationOccurred('error');

            if (error instanceof ApiError && error.reason !== null) {
                if (error.reason === 'channel_gate') {
                    setNotice({
                        kind: 'gate',
                        text: t('miniapp.check_in.gate'),
                        joinUrl: error.joinUrl,
                    });

                    return;
                }

                // A reason the catalogue has no sentence for (a new
                // CheckInRejection case this build has not met) degrades to
                // the generic refusal rather than showing a raw key.
                const key = `miniapp.check_in.refused.${error.reason}`;
                const sentence = t(key);

                setNotice({
                    kind: 'error',
                    text:
                        sentence === key
                            ? t('miniapp.check_in.unexpected')
                            : sentence,
                });

                return;
            }

            setNotice({
                kind: 'error',
                text: t('miniapp.check_in.unexpected'),
            });
        } finally {
            setPending(false);
        }
    }, [canTap, challenge.id, pending]);

    useEffect(() => {
        const app = webApp();

        if (app === null || !canTap) {
            return;
        }

        app.MainButton.setText(
            pending
                ? t('miniapp.check_in.working')
                : t('miniapp.check_in.button'),
        );
        app.MainButton.show();
        app.MainButton.onClick(tap);

        return () => {
            app.MainButton.offClick(tap);
            app.MainButton.hide();
        };
    }, [canTap, pending, tap]);

    return (
        <div className="flex flex-col gap-4">
            <header>
                <div className="flex items-center gap-2">
                    <h1 className="text-xl leading-tight font-bold">
                        {challenge.title}
                    </h1>

                    {challenge.is_creator ? (
                        <span className="rounded-full border border-[var(--tg-separator)] px-2 py-0.5 text-xs text-[var(--tg-hint)]">
                            {t('miniapp.challenges.creator')}
                        </span>
                    ) : null}
                </div>

                <p className="mt-1 text-sm text-[var(--tg-hint)]">
                    {challenge.status.label} · {challenge.period_type.label} ·{' '}
                    {challenge.proof_type.label}
                </p>

                {challenge.description !== null ? (
                    <p className="mt-2 text-sm">{challenge.description}</p>
                ) : null}
            </header>

            {notice !== null ? <NoticeBanner notice={notice} /> : null}

            <section className="grid grid-cols-3 gap-3">
                <Stat
                    label={t('miniapp.challenges.streak')}
                    value={challenge.me.current_streak}
                />
                <Stat
                    label={t('miniapp.challenges.best')}
                    value={challenge.me.longest_streak}
                />
                <Stat
                    label={t('miniapp.challenges.freezes')}
                    value={challenge.me.freezes.remaining}
                    caption={t('miniapp.challenges.freeze_count', {
                        used: challenge.me.freezes.used,
                        total: challenge.me.freezes.total,
                    })}
                />
            </section>

            {period !== null ? (
                <CurrentPeriodCard challenge={challenge} period={period} />
            ) : (
                <p className="rounded-2xl bg-[var(--tg-section-bg)] p-4 text-sm text-[var(--tg-hint)]">
                    {challenge.status.value === 'scheduled'
                        ? t('miniapp.challenges.not_started')
                        : t('miniapp.challenges.finished')}
                </p>
            )}

            <section>
                <Progress challenge={challenge} />
                <HistoryGrid challenge={challenge} />
            </section>
        </div>
    );
}

function NoticeBanner({ notice }: { notice: Notice }) {
    if (notice.kind === 'success') {
        return (
            <p className="rounded-xl bg-green-500/15 px-4 py-3 text-sm text-green-600">
                {t('miniapp.check_in.done')}
            </p>
        );
    }

    const app = webApp();

    if (notice.kind === 'gate') {
        // Narrowed once: TS will not keep `notice.joinUrl` non-null through
        // the closure below, but a const it will.
        const { joinUrl } = notice;

        return (
            <div className="rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600">
                <p>{notice.text}</p>

                {joinUrl !== null && app !== null ? (
                    <button
                        type="button"
                        onClick={() => app.openTelegramLink(joinUrl)}
                        className="mt-2 rounded-full bg-[var(--tg-button)] px-4 py-1.5 text-sm font-medium text-[var(--tg-button-text)]"
                    >
                        {t('miniapp.check_in.join')}
                    </button>
                ) : (
                    <p className="mt-1 text-xs">
                        {t('miniapp.check_in.no_link')}
                    </p>
                )}
            </div>
        );
    }

    return (
        <p className="rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600">
            {notice.text}
        </p>
    );
}

function Stat({
    label,
    value,
    caption,
}: {
    label: string;
    value: number;
    caption?: string;
}) {
    return (
        <div className="rounded-2xl bg-[var(--tg-section-bg)] p-3 text-center">
            <p className="text-2xl font-bold">{value}</p>
            <p className="text-xs text-[var(--tg-hint)]">{label}</p>
            {caption !== undefined ? (
                <p className="mt-0.5 text-[10px] text-[var(--tg-hint)]">
                    {caption}
                </p>
            ) : null}
        </div>
    );
}

function CurrentPeriodCard({
    challenge,
    period,
}: {
    challenge: ChallengeView;
    period: NonNullable<ChallengeView['current_period']>;
}) {
    const owed = period.owes_check_in;

    return (
        <section className="rounded-2xl bg-[var(--tg-section-bg)] p-4">
            <div className="flex items-center justify-between">
                <p className="text-sm font-semibold">
                    {t('miniapp.challenges.period_label', {
                        index: period.index + 1,
                        total: challenge.total_periods,
                    })}
                </p>

                <span
                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                        owed
                            ? 'bg-[var(--tg-button)] text-[var(--tg-button-text)]'
                            : 'bg-green-500/15 text-green-600'
                    }`}
                >
                    {owed
                        ? t('miniapp.challenges.check_in_due')
                        : t('miniapp.challenges.all_set')}
                </span>
            </div>

            <p className="mt-2 text-sm text-[var(--tg-hint)]">
                {t('miniapp.challenges.ends', {
                    when: formatWhen(period.ends_at),
                })}
            </p>

            {owed && challenge.proof_type.value !== 'button' ? (
                <p className="mt-2 text-sm text-[var(--tg-hint)]">
                    {t('miniapp.challenges.via_bot')}
                </p>
            ) : null}
        </section>
    );
}

function Progress({ challenge }: { challenge: ChallengeView }) {
    // The participant's own obligations, not the timeline's: a late joiner's
    // bar fills from their join, and the resource's history already starts
    // there, so the two agree.
    const own = challenge.total_periods - challenge.me.joined_period_index;
    const settled = challenge.history.length;
    const percent = own === 0 ? 0 : Math.round((settled / own) * 100);

    return (
        <div>
            <div className="flex items-center justify-between text-xs text-[var(--tg-hint)]">
                <span>{t('miniapp.challenges.progress')}</span>
                <span>
                    {settled} / {own}
                </span>
            </div>

            <div className="mt-1 h-2 overflow-hidden rounded-full bg-[var(--tg-secondary-bg)]">
                <div
                    className="h-full rounded-full bg-[var(--tg-button)] transition-all"
                    style={{ width: `${percent}%` }}
                />
            </div>
        </div>
    );
}

function HistoryGrid({ challenge }: { challenge: ChallengeView }) {
    const byIndex = new Map(
        challenge.history.map((entry) => [entry.index, entry]),
    );

    const period = challenge.current_period;

    return (
        <div className="mt-3">
            <p className="text-xs text-[var(--tg-hint)]">
                {t('miniapp.challenges.history')}
            </p>

            <ul className="mt-2 flex flex-wrap gap-1.5" dir="ltr">
                {Array.from({ length: challenge.total_periods }, (_, index) => (
                    <li key={index}>
                        <HistoryDot
                            state={historyDotState(
                                index,
                                byIndex.get(index)?.status.value ?? null,
                                challenge.me.joined_period_index,
                                period?.index,
                            )}
                            title={byIndex.get(index)?.status.label ?? ''}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function historyDotState(
    index: number,
    status: string | null,
    joinedAt: number,
    currentIndex: number | undefined,
): 'not-mine' | 'current' | 'approved' | 'missed' | 'frozen' | 'open' {
    if (index < joinedAt) {
        return 'not-mine';
    }

    if (status !== null) {
        if (status === 'approved') {
            return 'approved';
        }

        if (status === 'missed') {
            return 'missed';
        }

        if (status === 'frozen') {
            return 'frozen';
        }

        return 'open';
    }

    return index === currentIndex ? 'current' : 'open';
}

const DOT_CLASSES: Record<string, string> = {
    'not-mine': 'bg-neutral-300',
    current: 'border-2 border-[var(--tg-button)] bg-transparent',
    approved: 'bg-green-500',
    missed: 'bg-red-500',
    frozen: 'bg-sky-400',
    open: 'bg-neutral-400',
};

function HistoryDot({ state, title }: { state: string; title: string }) {
    return (
        <span
            title={title}
            className={`block size-4 rounded-full ${DOT_CLASSES[state]}`}
        />
    );
}

/**
 * A period boundary in the user's own locale. The challenge's timezone is
 * server-side business (the API already states boundaries in UTC); the Mini
 * App's job is only to not surprise the reader with an unfamiliar format.
 */
function formatWhen(iso: string): string {
    return new Intl.DateTimeFormat(localization.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

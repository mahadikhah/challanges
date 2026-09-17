import { useCallback, useEffect, useState } from 'react';

import { ApiError, joinChallenge, submitCheckIn } from '../api';
import { localization, t } from '../localization';
import { webApp } from '../telegram';
import type { ChallengeView } from '../types';

/**
 * One challenge as the participant's dashboard: the numbers (streak, best,
 * freezes), the period open right now with its verdict, progress through the
 * whole timeline, and the history grid behind it.
 *
 * A creator who has not joined their own challenge yet sees the same screen
 * minus everything that would have to be invented for them: no stats grid, no
 * history grid, and a pill that says they are not in it rather than "Checked
 * in". What stays is the challenge's own — title, description, the period open
 * now and the timeline behind it. Where a stats grid would be sits the join
 * button, and the refusal that answered it if it was refused.
 *
 * The one-tap check-in is the Telegram MainButton while — and only while —
 * the current period is owed and the challenge's proof is a tap. Every other
 * proof type keeps its path in the bot, and the card says so instead of
 * offering a button that could not work. The join button is deliberately *not*
 * the MainButton: there is one MainButton and the check-in effect already owns
 * it, so a second claimant would make correctness depend on the order React
 * runs two cleanups in across the join→joined transition.
 */

type Notice =
    | { kind: 'success'; text?: string }
    | { kind: 'error'; text: string }
    | { kind: 'gate'; text: string; joinUrl: string | null }
    // The join refusal that is a quote rather than a wall. It carries the
    // numbers, not a sentence: the banner owns the wording and the shortfall
    // arithmetic, and it is the only thing that renders this.
    | { kind: 'price'; price: number; balance: number };

export function ChallengeDetail({ initial }: { initial: ChallengeView }) {
    const [challenge, setChallenge] = useState(initial);
    const [pending, setPending] = useState(false);
    const [notice, setNotice] = useState<Notice | null>(null);
    const [value, setValue] = useState('');

    const period = challenge.current_period;
    const me = challenge.me;
    // A quantity challenge is scored on a number; a binary one is not asked.
    const scoring = challenge.scoring;
    const canTap =
        me !== null &&
        period !== null &&
        period.owes_check_in &&
        challenge.proof_type.value === 'button';

    const tap = useCallback(async () => {
        if (!canTap || pending) {
            return;
        }

        // The number is part of the submission on a scoring challenge — the
        // server refuses one without it (`value_required`), and saying so here
        // is kinder than letting it.
        let reported: number | undefined;

        if (scoring !== null) {
            const trimmed = value.trim();

            if (trimmed === '') {
                setNotice({
                    kind: 'error',
                    text: t('miniapp.check_in.value_missing'),
                });

                return;
            }

            const parsed = Number(trimmed);

            if (!Number.isFinite(parsed) || parsed < 0) {
                setNotice({
                    kind: 'error',
                    text: t('miniapp.check_in.value_invalid'),
                });

                return;
            }

            reported = parsed;
        }

        setPending(true);
        setNotice(null);

        try {
            const updated = await submitCheckIn(challenge.id, reported);

            setChallenge(updated);
            setNotice({ kind: 'success', text: scoredLine(updated) });
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
    }, [canTap, challenge.id, pending, scoring, value]);

    /**
     * Join this challenge. Same shape as `tap`: one request, the answer
     * replaces the whole view, and a refusal is a reason the catalogue turns
     * into a sentence.
     *
     * No `me !== null` guard is needed — the button that calls this only
     * exists in the not-joined state — but the server would answer anyway, and
     * idempotently: joining twice costs one slot.
     */
    const join = useCallback(async () => {
        if (pending) {
            return;
        }

        setPending(true);
        setNotice(null);

        try {
            const updated = await joinChallenge(challenge.id);

            setChallenge(updated);
            setNotice({ kind: 'success', text: t('miniapp.join.done') });
            webApp()?.HapticFeedback?.notificationOccurred('success');
        } catch (error) {
            webApp()?.HapticFeedback?.notificationOccurred('error');

            if (error instanceof ApiError && error.reason !== null) {
                if (error.reason === 'channel_gate') {
                    setNotice({
                        kind: 'gate',
                        text: t('miniapp.join.gate'),
                        joinUrl: error.joinUrl,
                    });

                    return;
                }

                // Out of slots, but the server said what another costs and
                // what they hold — so the banner can quote a shortfall instead
                // of stopping at "no".
                if (
                    error.reason === 'no_join_slot' &&
                    error.price !== null &&
                    error.balance !== null
                ) {
                    setNotice({
                        kind: 'price',
                        price: error.price,
                        balance: error.balance,
                    });

                    return;
                }

                // Same degradation as the check-in path: a reason this build
                // has no sentence for shows the generic refusal rather than a
                // raw key.
                const key = `miniapp.join.refused.${error.reason}`;
                const sentence = t(key);

                setNotice({
                    kind: 'error',
                    text:
                        sentence === key
                            ? t('miniapp.join.unexpected')
                            : sentence,
                });

                return;
            }

            setNotice({
                kind: 'error',
                text: t('miniapp.join.unexpected'),
            });
        } finally {
            setPending(false);
        }
    }, [challenge.id, pending]);

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

            {canTap && scoring !== null ? (
                <label className="block rounded-2xl bg-[var(--tg-section-bg)] p-4">
                    <span className="text-sm font-semibold">
                        {t('miniapp.check_in.value_prompt', {
                            unit: scoring.unit_label,
                        })}
                    </span>

                    <input
                        type="text"
                        inputMode="decimal"
                        value={value}
                        onChange={(event) => setValue(event.target.value)}
                        placeholder={t('miniapp.check_in.value_placeholder')}
                        disabled={pending}
                        dir="ltr"
                        className="mt-2 w-full rounded-xl border border-[var(--tg-separator)] bg-transparent px-3 py-2 text-base outline-none focus:border-[var(--tg-button)]"
                    />
                </label>
            ) : null}

            {me === null ? (
                <NotJoinedCard pending={pending} onJoin={join} />
            ) : (
                <section
                    className={`grid gap-3 ${scoring === null ? 'grid-cols-3' : 'grid-cols-4'}`}
                >
                    <Stat
                        label={t('miniapp.challenges.streak')}
                        value={me.current_streak}
                    />
                    <Stat
                        label={t('miniapp.challenges.best')}
                        value={me.longest_streak}
                    />
                    {scoring !== null ? (
                        <Stat
                            label={t('miniapp.challenges.score')}
                            value={me.total_score}
                        />
                    ) : null}
                    <Stat
                        label={t('miniapp.challenges.freezes')}
                        value={me.freezes.remaining}
                        caption={t('miniapp.challenges.freeze_count', {
                            used: me.freezes.used,
                            total: me.freezes.total,
                        })}
                    />
                </section>
            )}

            {period !== null ? (
                <CurrentPeriodCard
                    challenge={challenge}
                    period={period}
                    joined={me !== null}
                />
            ) : (
                <p className="rounded-2xl bg-[var(--tg-section-bg)] p-4 text-sm text-[var(--tg-hint)]">
                    {challenge.status.value === 'scheduled'
                        ? t('miniapp.challenges.not_started')
                        : t('miniapp.challenges.finished')}
                </p>
            )}

            <section>
                <Progress challenge={challenge} />
                {me !== null ? (
                    <HistoryGrid
                        challenge={challenge}
                        joinedAt={me.joined_period_index}
                    />
                ) : null}
            </section>
        </div>
    );
}

/**
 * What a creator who has not joined their own challenge sees instead of the
 * stats grid. It says the numbers are absent, not zero — there is no streak to
 * keep, and a zeroed tile would be a claim about somebody who is not in the
 * challenge — and it is where the way in lives.
 *
 * The button is offered whatever the challenge's status. Hiding it for a
 * closed or finished one would mean this screen carried its own copy of
 * `ChallengeStatus::acceptsJoins()`, and the refusal the server already sends
 * ("this challenge is no longer running") would become unreachable copy.
 */
function NotJoinedCard({
    pending,
    onJoin,
}: {
    pending: boolean;
    onJoin: () => void;
}) {
    return (
        <section className="rounded-2xl bg-[var(--tg-section-bg)] p-4">
            <p className="text-sm font-semibold">
                {t('miniapp.challenges.not_joined')}
            </p>

            <p className="mt-1 text-sm text-[var(--tg-hint)]">
                {t('miniapp.challenges.not_joined_body')}
            </p>

            <button
                type="button"
                onClick={onJoin}
                disabled={pending}
                className="mt-3 rounded-full bg-[var(--tg-button)] px-4 py-1.5 text-sm font-medium text-[var(--tg-button-text)] disabled:opacity-50"
            >
                {t(pending ? 'miniapp.join.working' : 'miniapp.join.button')}
            </button>
        </section>
    );
}

function NoticeBanner({ notice }: { notice: Notice }) {
    if (notice.kind === 'success') {
        return (
            <p className="rounded-xl bg-green-500/15 px-4 py-3 text-sm text-green-600">
                {notice.text ?? t('miniapp.check_in.done')}
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

    // The shortfall line appears only when the balance genuinely cannot cover
    // the price. Out of *slots* is not out of *money*: a reader holding a
    // thousand coins and no joining slot is not short, and the sentence would
    // otherwise tell them they are 0 coins short.
    if (notice.kind === 'price') {
        const short = notice.price - notice.balance;

        return (
            <div className="rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600">
                <p>{t('miniapp.join.no_slot')}</p>

                <p className="mt-1">
                    {t('miniapp.join.price', {
                        price: notice.price,
                        balance: notice.balance,
                    })}
                </p>

                {short > 0 ? (
                    <p className="mt-1 font-medium">
                        {t('miniapp.join.short', { coins: short })}
                    </p>
                ) : null}
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
    joined,
}: {
    challenge: ChallengeView;
    period: NonNullable<ChallengeView['current_period']>;
    joined: boolean;
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

                {/* Three states, not two. Without the participant branch a
                    viewer who never joined owes nothing, and the two-way pill
                    would answer "Checked in" — about somebody who is not in
                    the challenge. */}
                <span
                    className={`rounded-full px-2.5 py-1 text-xs font-medium ${
                        !joined
                            ? 'border border-[var(--tg-separator)] text-[var(--tg-hint)]'
                            : owed
                              ? 'bg-[var(--tg-button)] text-[var(--tg-button-text)]'
                              : 'bg-green-500/15 text-green-600'
                    }`}
                >
                    {!joined
                        ? t('miniapp.challenges.not_joined')
                        : owed
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
    const me = challenge.me;
    const period = challenge.current_period;

    // Two different questions wear the same bar. A participant's is their own
    // obligations — a late joiner's fills from their join, and the resource's
    // history already starts there, so the two agree. A viewer with no
    // participation owes nothing, so theirs is the *timeline*: how far the
    // shared clock has run. `period.index` counts closed periods because the
    // timeline is materialised whole and contiguous.
    const settled =
        me === null
            ? (period?.index ?? challenge.total_periods)
            : challenge.history.length;
    const own =
        me === null
            ? challenge.total_periods
            : challenge.total_periods - me.joined_period_index;
    const percent = own === 0 ? 0 : Math.round((settled / own) * 100);

    return (
        <div>
            <div className="flex items-center justify-between text-xs text-[var(--tg-hint)]">
                <span>
                    {t(
                        me === null
                            ? 'miniapp.challenges.timeline_progress'
                            : 'miniapp.challenges.progress',
                    )}
                </span>
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

function HistoryGrid({
    challenge,
    joinedAt,
}: {
    challenge: ChallengeView;
    joinedAt: number;
}) {
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
                                joinedAt,
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
 * The scored success line — "45 pushups — 150 points!" — read straight off
 * the settlement the response carries, so what the participant just saw
 * confirmed is what the server actually scored. Undefined on a binary
 * challenge, whose success stays the plain sentence.
 */
function scoredLine(challenge: ChallengeView): string | undefined {
    if (challenge.scoring === null) {
        return undefined;
    }

    const checkIn = challenge.current_period?.check_in;

    if (
        checkIn === undefined ||
        checkIn === null ||
        checkIn.score === null ||
        checkIn.score === undefined
    ) {
        return undefined;
    }

    return t('miniapp.check_in.scored', {
        value: checkIn.reported_value ?? 0,
        unit: challenge.scoring.unit_label,
        score: checkIn.score,
    });
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

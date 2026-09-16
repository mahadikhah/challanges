import { t } from '../localization';
import type { ChallengeView } from '../types';

/**
 * The Mini App's home: one card per challenge the user is in or runs, each
 * carrying the one question a dashboard exists to answer — "am I okay right
 * now?" — as the owes banner, with streak and freezes as the numbers worth
 * watching.
 *
 * A creator who has not joined their own challenge yet has no streak and no
 * freezes to show, and gets a badge saying so instead of two zeroes. The
 * timeline's position is shown either way, because that belongs to the
 * challenge rather than to the viewer.
 */

export function ChallengeList({
    challenges,
    onOpen,
}: {
    challenges: ChallengeView[];
    onOpen: (challenge: ChallengeView) => void;
}) {
    if (challenges.length === 0) {
        return (
            <p className="px-4 pt-16 text-center text-[var(--tg-hint)]">
                {t('miniapp.challenges.empty')}
            </p>
        );
    }

    return (
        <ul className="flex flex-col gap-3">
            {challenges.map((challenge) => (
                <li key={challenge.id}>
                    <ChallengeCard challenge={challenge} onOpen={onOpen} />
                </li>
            ))}
        </ul>
    );
}

function ChallengeCard({
    challenge,
    onOpen,
}: {
    challenge: ChallengeView;
    onOpen: (challenge: ChallengeView) => void;
}) {
    const period = challenge.current_period;
    const me = challenge.me;

    return (
        <button
            type="button"
            onClick={() => onOpen(challenge)}
            className="block w-full rounded-2xl bg-[var(--tg-section-bg)] p-4 text-start shadow-sm active:opacity-70"
        >
            <div className="flex items-start justify-between gap-2">
                <h2 className="text-base leading-snug font-semibold">
                    {challenge.title}
                </h2>

                {period !== null && period.owes_check_in ? (
                    <span className="shrink-0 rounded-full bg-[var(--tg-button)] px-2.5 py-1 text-xs font-medium text-[var(--tg-button-text)]">
                        {t('miniapp.challenges.check_in_due')}
                    </span>
                ) : null}
            </div>

            {challenge.description !== null ? (
                <p className="mt-1 line-clamp-2 text-sm text-[var(--tg-hint)]">
                    {challenge.description}
                </p>
            ) : null}

            <div className="mt-3 flex items-center gap-4 text-sm">
                {me === null ? (
                    <span className="rounded-full border border-[var(--tg-separator)] px-2 py-0.5 text-xs text-[var(--tg-hint)]">
                        {t('miniapp.challenges.not_joined')}
                    </span>
                ) : (
                    <>
                        <span aria-label={t('miniapp.challenges.streak')}>
                            🔥 {me.current_streak}
                        </span>

                        <span aria-label={t('miniapp.challenges.freezes')}>
                            ❄️ {me.freezes.remaining}
                        </span>
                    </>
                )}

                <span className="ms-auto text-[var(--tg-hint)]">
                    {period !== null
                        ? t('miniapp.challenges.period_label', {
                              index: period.index + 1,
                              total: challenge.total_periods,
                          })
                        : challenge.status.label}
                </span>
            </div>
        </button>
    );
}

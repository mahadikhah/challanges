import { useCallback, useEffect, useState } from 'react';

import { ApiError, authenticate, fetchChallenges } from './api';
import { t } from './localization';
import { ChallengeDetail } from './screens/challenge-detail';
import { ChallengeList } from './screens/challenge-list';
import { supports, webApp } from './telegram';
import { applyTheme } from './theme';
import type { ChallengeView, MiniAppUser } from './types';

/**
 * The Mini App's root: authenticate → list ↔ detail.
 *
 * Boot ends one of three ways, and they are not interchangeable. *Outside
 * Telegram* — no `WebApp` object, or one that handed us an empty `initData`:
 * there is nobody to authenticate, and the fix is to open the app from the bot,
 * not to try again. *Refused* — Telegram vouched for nothing we sent, and a
 * fresh open is the only way to get fresh `initData`. *Signed in* — the token is
 * minted and we have a user.
 *
 * The listing is the *signed-in* state's own business, not a fourth boot
 * outcome: a request that fails after a successful exchange is a failed
 * request, not a failed identity, and it is worth retrying without reopening
 * anything. It used to share the refusal's screen, so a flaky `/challenges`
 * told the user their Telegram identity could not be verified — which they had
 * just watched be verified.
 *
 * The list hands the whole `ChallengeView` to the detail screen rather than
 * an id. The detail then never re-fetches on first paint — the list's data
 * is fresh by definition — while the check-in response replaces the whole
 * state, so there is exactly one place data can go stale and exactly one
 * place it is refreshed.
 */

type ListState =
    | { status: 'loading' }
    | { status: 'ready'; challenges: ChallengeView[] }
    | { status: 'unreachable'; httpStatus: number | null };

type Phase =
    | { screen: 'authenticating' }
    | { screen: 'outside' }
    | { screen: 'refused' }
    | { screen: 'list'; state: ListState }
    | { screen: 'detail'; challenge: ChallengeView };

/**
 * Which screen the very first render is on.
 *
 * Whether we are inside Telegram is knowable synchronously — the shell loads
 * `telegram-web-app.js` before this bundle — so it is derived rather than
 * assigned from an effect. Assigning it later would paint an "authenticating"
 * frame for a state we already knew was wrong.
 */
function bootScreen(): Phase {
    return (webApp()?.initData ?? '') === ''
        ? { screen: 'outside' }
        : { screen: 'authenticating' };
}

export function MiniApp() {
    const [phase, setPhase] = useState<Phase>(bootScreen);
    const [user, setUser] = useState<MiniAppUser | null>(null);

    /**
     * The listing, with its failure kept to itself.
     *
     * Separate from the exchange on purpose: nothing here can produce the
     * identity screen, because the identity is already established by the time
     * it runs.
     */
    const loadList = useCallback(() => {
        setPhase({ screen: 'list', state: { status: 'loading' } });

        fetchChallenges().then(
            (challenges) => {
                setPhase({
                    screen: 'list',
                    state: { status: 'ready', challenges },
                });
            },
            (failure: unknown) => {
                setPhase({
                    screen: 'list',
                    state: {
                        status: 'unreachable',
                        httpStatus:
                            failure instanceof ApiError ? failure.status : null,
                    },
                });
            },
        );
    }, []);

    useEffect(() => {
        const app = webApp();

        if (app !== null) {
            app.ready();
            app.expand();

            applyTheme(app.themeParams);
            app.onEvent('themeChanged', () => applyTheme(app.themeParams));
        }

        const initData = app?.initData ?? '';

        if (initData === '') {
            // Both shapes of "not really in Telegram" — no `WebApp` object at
            // all (a desktop browser), and one that loaded without `initData`
            // (a plain-HTTP page, where Telegram refuses to hand it over) —
            // were already reflected by `bootScreen()`. There is no identity to
            // exchange, so there is nothing left to do.
            return;
        }

        // The exchange's own rejection handler, and the only place a failure is
        // read as an identity failure. `loadList` is called from the success
        // branch rather than chained onto this promise, so the two cannot be
        // caught by the same handler however the promise chain is edited later.
        authenticate(initData).then(
            (bootUser) => {
                setUser(bootUser);

                loadList();
            },
            () => {
                setPhase({ screen: 'refused' });
            },
        );
    }, [loadList]);

    const openDetail = useCallback((challenge: ChallengeView) => {
        setPhase({ screen: 'detail', challenge });
    }, []);

    const backToList = useCallback(() => {
        // The check-in already replaced the detail's state wholesale, so the
        // list behind it is stale only in the one challenge the participant
        // acted on — cheapest correct refresh is to fetch again. A failure here
        // leaves them on the detail they were reading rather than throwing the
        // screen away: they asked to go back, and the detail is still valid.
        fetchChallenges().then(
            (challenges) => {
                setPhase({
                    screen: 'list',
                    state: { status: 'ready', challenges },
                });
            },
            () => {
                // Deliberately nothing.
            },
        );
    }, []);

    useEffect(() => {
        const app = webApp();

        if (app === null || !supports(app, '6.1')) {
            return;
        }

        if (phase.screen === 'detail') {
            app.BackButton.show();
            app.BackButton.onClick(backToList);
        } else {
            app.BackButton.hide();
        }

        return () => {
            app.BackButton.offClick(backToList);
        };
    }, [phase.screen, backToList]);

    // A deep link (`t.me/<bot>/<app>?startapp=…`) names a challenge to open
    // — but resolving its code to a challenge needs a join endpoint the API
    // does not have yet, so it is recorded as a follow-up and ignored here.

    return (
        <div className="mx-auto flex min-h-dvh w-full max-w-lg flex-col px-4 pt-3 pb-8">
            {user !== null && phase.screen !== 'detail' ? (
                <header className="mb-4 flex items-center justify-between">
                    <h1 className="text-lg font-bold">
                        {t('miniapp.challenges.title')}
                    </h1>
                    <span className="text-sm text-[var(--tg-hint)]">
                        {t('miniapp.coins', { count: user.coins })}
                    </span>
                </header>
            ) : null}

            <main className="flex-1">
                {phase.screen === 'authenticating' ||
                (phase.screen === 'list' &&
                    phase.state.status === 'loading') ? (
                    <p className="pt-16 text-center text-[var(--tg-hint)]">
                        {t('common.loading')}
                    </p>
                ) : null}

                {phase.screen === 'outside' ? (
                    <Notice message={t('miniapp.auth.outside_telegram')} />
                ) : null}

                {phase.screen === 'refused' ? (
                    <Notice message={t('miniapp.auth.failed')} />
                ) : null}

                {phase.screen === 'list' &&
                phase.state.status === 'unreachable' ? (
                    <Notice
                        message={t('miniapp.auth.data_failed')}
                        // Only when the API answered at all. A dropped
                        // connection has no status, and inventing one would be
                        // the same misattribution this screen exists to fix.
                        hint={
                            phase.state.httpStatus === null
                                ? undefined
                                : t('miniapp.auth.data_failed_status', {
                                      status: phase.state.httpStatus,
                                  })
                        }
                        onRetry={loadList}
                    />
                ) : null}

                {phase.screen === 'list' && phase.state.status === 'ready' ? (
                    <ChallengeList
                        challenges={phase.state.challenges}
                        onOpen={openDetail}
                    />
                ) : null}

                {phase.screen === 'detail' ? (
                    <ChallengeDetail initial={phase.challenge} />
                ) : null}
            </main>
        </div>
    );
}

/**
 * A sentence, an optional technical line, and an optional way to try again.
 *
 * The hint is small and dim on purpose: it is what a user quotes when they
 * report the problem, not what they are meant to act on.
 */
function Notice({
    message,
    hint,
    onRetry,
}: {
    message: string;
    hint?: string;
    onRetry?: () => void;
}) {
    return (
        <div className="pt-16 text-center">
            <p className="text-sm text-[var(--tg-hint)]">{message}</p>

            {hint !== undefined ? (
                <p className="mt-2 text-xs text-[var(--tg-hint)] opacity-70">
                    {hint}
                </p>
            ) : null}

            {onRetry !== undefined ? (
                <button
                    type="button"
                    onClick={onRetry}
                    className="mt-4 rounded-full bg-[var(--tg-button)] px-4 py-1.5 text-sm font-medium text-[var(--tg-button-text)]"
                >
                    {t('miniapp.auth.retry')}
                </button>
            ) : null}
        </div>
    );
}

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
 * Boot ends one of four ways, and they are not interchangeable. *Outside
 * Telegram* — no `WebApp` object, or one that handed us an empty `initData`:
 * there is nobody to authenticate, and the fix is to open the app from the bot,
 * not to try again. *Refused* — the server answered 401, so Telegram vouched
 * for nothing we sent, and a fresh open is the only way to get fresh
 * `initData`. *Unreachable* — no usable answer at all, so the identity was never
 * actually judged and retrying is worth the tap. *Signed in* — the token is
 * minted and we have a user.
 *
 * The listing is the *signed-in* state's own business, not a fifth boot
 * outcome: a request that fails after a successful exchange is a failed
 * request, not a failed identity, and it is worth retrying without reopening
 * anything. It used to share the refusal's screen, so a flaky `/challenges`
 * told the user their Telegram identity could not be verified — which they had
 * just watched be verified. The exchange had the same flaw in the other
 * direction: a dropped connection and a 500 were both reported as a refusal, so
 * the one sentence a user could quote covered three unrelated faults — and the
 * server had already logged which one it was, where nobody reporting a bug from
 * a phone can read it.
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
    | { screen: 'unreachable'; httpStatus: number | null }
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

/**
 * Which screen a failed exchange deserves.
 *
 * A 401 is the server refusing the identity, and the only thing that clears it
 * is fresh `initData` — a new open. Everything else is the exchange *failing*
 * rather than the identity being judged: a dropped connection, a 500, a proxy
 * answering with its own error page. Telling the user their Telegram identity
 * could not be verified in those cases blames the one thing that was never in
 * question, and sends them to close and reopen an app that was never the
 * problem.
 *
 * The split is deliberately transport-versus-verdict, not one refusal reason
 * against another: the server keeps *why* it refused to itself, because a
 * caller who can tell "tampered" from "outdated" learns which of their
 * forgeries is closest to working.
 */
function identityFailure(failure: unknown): Phase {
    if (failure instanceof ApiError && failure.status === 401) {
        return { screen: 'refused' };
    }

    return {
        screen: 'unreachable',
        httpStatus: failure instanceof ApiError ? failure.status : null,
    };
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

    /**
     * Trade `initData` for a token, and land on whichever screen the answer
     * deserves.
     *
     * Deliberately does not put the app back on `authenticating` first. On the
     * boot path `bootScreen()` has already chosen that screen synchronously, so
     * setting it again from inside the effect is a second render that changes
     * nothing — and `react-hooks/set-state-in-effect` is right to refuse it. The
     * retry path, which does need the transition, sets it at its own call site.
     *
     * `loadList` is called from the success branch rather than chained onto
     * this promise, so a listing failure can never be caught by the exchange's
     * handler however the chain is edited later.
     */
    const signIn = useCallback(
        (initData: string) => {
            authenticate(initData).then(
                (bootUser) => {
                    setUser(bootUser);

                    loadList();
                },
                (failure: unknown) => {
                    setPhase(identityFailure(failure));
                },
            );
        },
        [loadList],
    );

    /**
     * Retry the exchange with the same `initData`, which is what the screen
     * offering this is for: an exchange that never got an answer says nothing
     * about the identity, so the same payload is worth sending again. Unlike
     * the boot path this one does need the loading screen, since it is leaving
     * a screen that is already painted.
     *
     * Re-read from the SDK rather than held in state because Telegram injects
     * `initData` once per open and never rotates it — the value behind a retry
     * is the one that just failed, which is precisely what is wanted.
     */
    const retrySignIn = useCallback(() => {
        setPhase({ screen: 'authenticating' });

        signIn(webApp()?.initData ?? '');
    }, [signIn]);

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

        signIn(initData);
    }, [signIn]);

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

                {phase.screen === 'unreachable' ? (
                    <Notice
                        message={t('miniapp.auth.unreachable')}
                        // The listing's status line, reused rather than twinned:
                        // the sentence is about the server answering, and says
                        // nothing about which of the two requests it answered.
                        // Absent when nothing answered at all, since inventing a
                        // status for a dropped connection is the misattribution
                        // this screen exists to undo.
                        hint={
                            phase.httpStatus === null
                                ? undefined
                                : t('miniapp.auth.data_failed_status', {
                                      status: phase.httpStatus,
                                  })
                        }
                        onRetry={retrySignIn}
                    />
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

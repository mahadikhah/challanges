import { useCallback, useEffect, useState } from 'react';

import { authenticate, fetchChallenges } from './api';
import { t } from './localization';
import { ChallengeDetail } from './screens/challenge-detail';
import { ChallengeList } from './screens/challenge-list';
import { supports, webApp } from './telegram';
import { applyTheme } from './theme';
import type { ChallengeView, MiniAppUser } from './types';

/**
 * The Mini App's root: authenticate → list ↔ detail.
 *
 * Boot is three states, never more. *Authenticating* — Telegram's initData is
 * being exchanged for the bearer token, which is the only moment the app is
 * allowed to look empty. *Signed in* — the two screens, with the BackButton
 * leaving a detail for the list. *Failed* — the identity could not be
 * verified, and there is nothing to fall back to: the message says to reopen
 * the app, because a fresh open brings a fresh initData to try again.
 *
 * The list hands the whole `ChallengeView` to the detail screen rather than
 * an id. The detail then never re-fetches on first paint — the list's data
 * is fresh by definition — while the check-in response replaces the whole
 * state, so there is exactly one place data can go stale and exactly one
 * place it is refreshed.
 */

type Phase =
    | { screen: 'authenticating' }
    | { screen: 'failed' }
    | { screen: 'list'; challenges: ChallengeView[] }
    | { screen: 'detail'; challenge: ChallengeView };

export function MiniApp() {
    const [phase, setPhase] = useState<Phase>({ screen: 'authenticating' });
    const [user, setUser] = useState<MiniAppUser | null>(null);

    useEffect(() => {
        const app = webApp();

        if (app !== null) {
            app.ready();
            app.expand();

            applyTheme(app.themeParams);
            app.onEvent('themeChanged', () => applyTheme(app.themeParams));
        }

        const initData = app?.initData ?? '';

        authenticate(initData)
            .then((bootUser) => {
                setUser(bootUser);

                return fetchChallenges();
            })
            .then((challenges) => {
                setPhase({ screen: 'list', challenges });
            })
            .catch(() => {
                setPhase({ screen: 'failed' });
            });
    }, []);

    const openDetail = useCallback((challenge: ChallengeView) => {
        setPhase({ screen: 'detail', challenge });
    }, []);

    const backToList = useCallback(() => {
        // The check-in already replaced the detail's state wholesale, so the
        // list behind it is stale only in the one challenge the participant
        // acted on — cheapest correct refresh is to fetch again.
        fetchChallenges()
            .then((challenges) => {
                setPhase({ screen: 'list', challenges });
            })
            .catch(() => {
                setPhase((current) =>
                    current.screen === 'detail'
                        ? {
                              screen: 'detail',
                              challenge: current.challenge,
                          }
                        : current,
                );
            });
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
                {phase.screen === 'authenticating' ? (
                    <p className="pt-16 text-center text-[var(--tg-hint)]">
                        {t('common.loading')}
                    </p>
                ) : null}

                {phase.screen === 'failed' ? <AuthFailed /> : null}

                {phase.screen === 'list' ? (
                    <ChallengeList
                        challenges={phase.challenges}
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

function AuthFailed() {
    return (
        <div className="pt-16 text-center">
            <p className="text-sm text-[var(--tg-hint)]">
                {t('miniapp.auth.failed')}
            </p>
        </div>
    );
}

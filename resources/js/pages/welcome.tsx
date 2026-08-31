import { Head, Link, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import AppearanceToggleTab from '@/components/appearance-tabs';
import LanguageSwitcher from '@/components/language-switcher';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard, login } from '@/routes';

type FeatureCardProps = {
    emoji: string;
    title: string;
    body: string;
};

function FeatureCard({ emoji, title, body }: FeatureCardProps) {
    return (
        <div className="flex flex-col gap-3 rounded-2xl border border-border bg-card p-6 text-card-foreground">
            <span aria-hidden className="text-2xl">
                {emoji}
            </span>
            <h3 className="text-lg font-semibold">{title}</h3>
            <p className="text-sm leading-relaxed text-muted-foreground">
                {body}
            </p>
        </div>
    );
}

type ShowcaseCardProps = {
    emoji: string;
    title: string;
    meta: string;
    streak: number;
    progress: number;
    progressLabel: string;
    freezes: number;
    freezesLabel: string;
    streakLabel: string;
};

/**
 * One example challenge card, drawn the way the Mini App draws a real one:
 * a pill for the cadence, a big streak number with the flame, a thin
 * progress bar, and a freeze pill when any are left. The numbers arrive
 * already resolved from the static table below — this component only
 * lays them out.
 */
function ShowcaseCard({
    emoji,
    title,
    meta,
    streak,
    progress,
    progressLabel,
    freezes,
    freezesLabel,
    streakLabel,
}: ShowcaseCardProps) {
    return (
        <div className="flex flex-col gap-4 rounded-2xl border border-border bg-card p-6 text-card-foreground">
            <div className="flex items-center gap-3">
                <span aria-hidden className="text-2xl">
                    {emoji}
                </span>
                <div className="min-w-0">
                    <h3 className="truncate font-semibold">{title}</h3>
                    <p className="text-xs text-muted-foreground">{meta}</p>
                </div>
            </div>

            <div className="flex items-baseline gap-2">
                <span aria-hidden>🔥</span>
                <span className="text-4xl font-bold tabular-nums">
                    {streak}
                </span>
                <span className="text-sm text-muted-foreground">
                    {streakLabel}
                </span>
            </div>

            <div className="flex flex-col gap-2">
                <div
                    role="progressbar"
                    aria-valuenow={progress}
                    aria-valuemin={0}
                    aria-valuemax={100}
                    className="h-2 rounded-full bg-muted"
                >
                    <div
                        className="h-2 rounded-full bg-primary"
                        style={{ width: `${progress}%` }}
                    />
                </div>
                <p className="text-xs text-muted-foreground">{progressLabel}</p>
            </div>

            {freezes > 0 && (
                <span className="inline-flex w-fit items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">
                    <span aria-hidden>❄️</span>
                    {freezes} {freezesLabel}
                </span>
            )}
        </div>
    );
}

/** Footer doc links point at the repository's blob URLs; the Farsi page links each doc's `.fa` sibling. */
const REPO_BLOB = 'https://github.com/mahadikhah/challanges/blob/master';

/**
 * The marketing landing — the one page whose whole job is explaining the
 * platform to someone who has never opened the bot. Copy lives in the
 * `website` lang group; the bot link arrives as a prop and may be null on a
 * box whose bot is not configured yet, so the CTA degrades to a note instead
 * of disappearing. Visual language follows the Mini App (rounded cards and
 * pills, emoji, big streak numbers, thin progress bars) but built on the
 * Forge tokens — never raw `neutral-*`.
 */
export default function Welcome({ bot_url }: { bot_url: string | null }) {
    const { t, locale } = useTranslation();
    const { auth } = usePage().props;

    const features: FeatureCardProps[] = [
        {
            emoji: '🔥',
            title: t('website.features.streaks.title'),
            body: t('website.features.streaks.body'),
        },
        {
            emoji: '📷',
            title: t('website.features.proof.title'),
            body: t('website.features.proof.body'),
        },
        {
            emoji: '🗓️',
            title: t('website.features.timeline.title'),
            body: t('website.features.timeline.body'),
        },
    ];

    const economy: FeatureCardProps[] = [
        {
            emoji: '👥',
            title: t('website.economy.invite.title'),
            body: t('website.economy.invite.body'),
        },
        {
            emoji: '🏆',
            title: t('website.economy.completion.title'),
            body: t('website.economy.completion.body'),
        },
        {
            emoji: '⭐',
            title: t('website.economy.stars.title'),
            body: t('website.economy.stars.body'),
        },
    ];

    // Illustrative, not live data: numbers are part of the marketing copy,
    // so they sit here next to the emoji rather than in the lang files.
    const showcase: ShowcaseCardProps[] = [
        {
            emoji: '🏃',
            title: t('website.showcase.morning.title'),
            meta: t('website.showcase.morning.meta'),
            streak: 34,
            progress: 57,
            progressLabel: t('website.showcase.morning.progress'),
            freezes: 1,
            freezesLabel: t('website.showcase.freezes_left'),
            streakLabel: t('website.showcase.streak'),
        },
        {
            emoji: '📚',
            title: t('website.showcase.reading.title'),
            meta: t('website.showcase.reading.meta'),
            streak: 9,
            progress: 75,
            progressLabel: t('website.showcase.reading.progress'),
            freezes: 2,
            freezesLabel: t('website.showcase.freezes_left'),
            streakLabel: t('website.showcase.streak'),
        },
        {
            emoji: '🗣️',
            title: t('website.showcase.language.title'),
            meta: t('website.showcase.language.meta'),
            streak: 45,
            progress: 33,
            progressLabel: t('website.showcase.language.progress'),
            freezes: 0,
            freezesLabel: t('website.showcase.freezes_left'),
            streakLabel: t('website.showcase.streak'),
        },
    ];

    const steps = ['start', 'create', 'checkin', 'streak'].map((key) => ({
        key,
        title: t(`website.how.${key}.title`),
        body: t(`website.how.${key}.body`),
    }));

    const fa = locale === 'fa' ? '.fa' : '';
    const docs = [
        { label: t('website.docs.readme'), path: `README${fa}.md` },
        {
            label: t('website.docs.setup_vps'),
            path: `docs/setup-vps${fa}.md`,
        },
        {
            label: t('website.docs.setup_cpanel'),
            path: `docs/setup-cpanel${fa}.md`,
        },
        {
            label: t('website.docs.user_flows'),
            path: `docs/user-flows${fa}.md`,
        },
    ];

    return (
        <>
            <Head title={t('website.title')} />

            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="border-b border-border">
                    <div className="mx-auto flex w-full max-w-5xl flex-wrap items-center justify-between gap-4 px-6 py-4">
                        <span className="text-lg font-semibold">
                            {t('common.app_name')}
                        </span>

                        <div className="flex flex-wrap items-center gap-3">
                            <LanguageSwitcher />
                            <AppearanceToggleTab />
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className="rounded-full border border-border px-4 py-1.5 text-sm font-medium hover:bg-muted"
                                >
                                    {t('website.dashboard')}
                                </Link>
                            ) : (
                                <Link
                                    href={login()}
                                    className="rounded-full border border-border px-4 py-1.5 text-sm font-medium hover:bg-muted"
                                >
                                    {t('website.login')}
                                </Link>
                            )}
                        </div>
                    </div>
                </header>

                <main className="flex-1">
                    {/* Hero */}
                    <section className="mx-auto w-full max-w-5xl px-6 py-20 text-center">
                        <h1 className="mx-auto max-w-3xl text-4xl leading-tight font-bold text-balance sm:text-5xl">
                            {t('website.title')}
                        </h1>
                        <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-pretty text-muted-foreground">
                            {t('website.lead')}
                        </p>

                        {bot_url !== null ? (
                            <a
                                href={bot_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                dir="ltr"
                                className="mt-10 inline-flex items-center gap-2 rounded-full bg-primary px-8 py-3 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                            >
                                <Send className="h-4 w-4" />
                                {t('website.open_bot')}
                            </a>
                        ) : (
                            <p className="mx-auto mt-10 max-w-md rounded-2xl bg-muted p-4 text-sm text-muted-foreground">
                                {t('website.bot_unavailable')}
                            </p>
                        )}
                    </section>

                    {/* Features */}
                    <section className="border-y border-border bg-muted/40">
                        <div className="mx-auto w-full max-w-5xl px-6 py-16">
                            <h2 className="mb-10 text-center text-2xl font-semibold">
                                {t('website.features.title')}
                            </h2>
                            <div className="grid gap-6 sm:grid-cols-1 lg:grid-cols-3">
                                {features.map((feature) => (
                                    <FeatureCard
                                        key={feature.title}
                                        {...feature}
                                    />
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* Showcase */}
                    <section className="mx-auto w-full max-w-5xl px-6 py-16">
                        <h2 className="text-center text-2xl font-semibold">
                            {t('website.showcase.title')}
                        </h2>
                        <p className="mx-auto mt-3 mb-10 max-w-2xl text-center text-sm text-muted-foreground">
                            {t('website.showcase.note')}
                        </p>
                        <div className="grid gap-6 sm:grid-cols-1 lg:grid-cols-3">
                            {showcase.map((card) => (
                                <ShowcaseCard key={card.title} {...card} />
                            ))}
                        </div>
                    </section>

                    {/* Economy */}
                    <section className="border-y border-border bg-muted/40">
                        <div className="mx-auto w-full max-w-5xl px-6 py-16">
                            <h2 className="mb-4 text-center text-2xl font-semibold">
                                {t('website.economy.title')}
                            </h2>
                            <p className="mx-auto mb-10 max-w-2xl text-center text-sm leading-relaxed text-muted-foreground">
                                {t('website.economy.body')}
                            </p>
                            <div className="grid gap-6 sm:grid-cols-1 lg:grid-cols-3">
                                {economy.map((feature) => (
                                    <FeatureCard
                                        key={feature.title}
                                        {...feature}
                                    />
                                ))}
                            </div>
                        </div>
                    </section>

                    {/* How it works */}
                    <section className="mx-auto w-full max-w-5xl px-6 py-16">
                        <h2 className="mb-10 text-center text-2xl font-semibold">
                            {t('website.how.title')}
                        </h2>
                        <ol className="grid gap-8 sm:grid-cols-1 lg:grid-cols-4">
                            {steps.map((step, index) => (
                                <li
                                    key={step.key}
                                    className="flex flex-col gap-2"
                                >
                                    <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-primary text-sm font-semibold text-primary-foreground">
                                        {index + 1}
                                    </span>
                                    <h3 className="mt-2 font-semibold">
                                        {step.title}
                                    </h3>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {step.body}
                                    </p>
                                </li>
                            ))}
                        </ol>
                    </section>
                </main>

                <footer className="border-t border-border">
                    <div className="mx-auto flex w-full max-w-5xl flex-col items-center gap-4 px-6 py-8 text-center text-sm text-muted-foreground">
                        <p>{t('website.footer')}</p>
                        <ul className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
                            {docs.map((doc) => (
                                <li key={doc.path}>
                                    <a
                                        href={`${REPO_BLOB}/${doc.path}`}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="underline-offset-4 hover:text-foreground hover:underline"
                                        dir="ltr"
                                    >
                                        {doc.label}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                </footer>
            </div>
        </>
    );
}

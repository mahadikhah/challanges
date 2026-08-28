import { Head, Link, usePage } from '@inertiajs/react';
import {
    Clock,
    Flame,
    Send,
    ShieldCheck,
    Star,
    Trophy,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import AppearanceToggleTab from '@/components/appearance-tabs';
import LanguageSwitcher from '@/components/language-switcher';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard, login } from '@/routes';

type FeatureCardProps = {
    icon: LucideIcon;
    title: string;
    body: string;
};

function FeatureCard({ icon: Icon, title, body }: FeatureCardProps) {
    return (
        <div className="flex flex-col gap-3 rounded-xl border border-neutral-200 p-6 dark:border-neutral-800">
            <Icon className="h-6 w-6 text-neutral-900 dark:text-neutral-100" />
            <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                {title}
            </h3>
            <p className="text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">
                {body}
            </p>
        </div>
    );
}

/**
 * The marketing landing — the one page whose whole job is explaining the
 * platform to someone who has never opened the bot. Copy lives in the
 * `website` lang group; the bot link arrives as a prop and may be null on a
 * box whose bot is not configured yet, so the CTA degrades to a note instead
 * of disappearing.
 */
export default function Welcome({ bot_url }: { bot_url: string | null }) {
    const { t } = useTranslation();
    const { auth } = usePage().props;

    const features: FeatureCardProps[] = [
        {
            icon: Flame,
            title: t('website.features.streaks.title'),
            body: t('website.features.streaks.body'),
        },
        {
            icon: ShieldCheck,
            title: t('website.features.proof.title'),
            body: t('website.features.proof.body'),
        },
        {
            icon: Clock,
            title: t('website.features.timeline.title'),
            body: t('website.features.timeline.body'),
        },
    ];

    const economy: FeatureCardProps[] = [
        {
            icon: Users,
            title: t('website.economy.invite.title'),
            body: t('website.economy.invite.body'),
        },
        {
            icon: Trophy,
            title: t('website.economy.completion.title'),
            body: t('website.economy.completion.body'),
        },
        {
            icon: Star,
            title: t('website.economy.stars.title'),
            body: t('website.economy.stars.body'),
        },
    ];

    const steps = ['start', 'create', 'checkin', 'streak'].map((key) => ({
        key,
        title: t(`website.how.${key}.title`),
        body: t(`website.how.${key}.body`),
    }));

    return (
        <>
            <Head title={t('website.title')} />

            <div className="flex min-h-screen flex-col bg-neutral-50 text-neutral-900 dark:bg-neutral-950 dark:text-neutral-100">
                <header className="border-b border-neutral-200 dark:border-neutral-800">
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
                                    className="text-sm font-medium text-neutral-600 underline-offset-4 hover:underline dark:text-neutral-400"
                                >
                                    {t('website.dashboard')}
                                </Link>
                            ) : (
                                <Link
                                    href={login()}
                                    className="text-sm font-medium text-neutral-600 underline-offset-4 hover:underline dark:text-neutral-400"
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
                        <p className="mx-auto mt-6 max-w-2xl text-lg leading-relaxed text-pretty text-neutral-600 dark:text-neutral-400">
                            {t('website.lead')}
                        </p>

                        {bot_url !== null ? (
                            <a
                                href={bot_url}
                                target="_blank"
                                rel="noopener noreferrer"
                                dir="ltr"
                                className="mt-10 inline-flex items-center gap-2 rounded-lg bg-neutral-900 px-6 py-3 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 dark:bg-neutral-100 dark:text-neutral-900 dark:hover:bg-neutral-300"
                            >
                                <Send className="h-4 w-4" />
                                {t('website.open_bot')}
                            </a>
                        ) : (
                            <p className="mx-auto mt-10 max-w-md text-sm text-neutral-500 dark:text-neutral-500">
                                {t('website.bot_unavailable')}
                            </p>
                        )}
                    </section>

                    {/* Features */}
                    <section className="border-y border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
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

                    {/* Economy */}
                    <section className="mx-auto w-full max-w-5xl px-6 py-16">
                        <h2 className="mb-4 text-center text-2xl font-semibold">
                            {t('website.economy.title')}
                        </h2>
                        <p className="mx-auto mb-10 max-w-2xl text-center text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">
                            {t('website.economy.body')}
                        </p>
                        <div className="grid gap-6 sm:grid-cols-1 lg:grid-cols-3">
                            {economy.map((feature) => (
                                <FeatureCard key={feature.title} {...feature} />
                            ))}
                        </div>
                    </section>

                    {/* How it works */}
                    <section className="border-t border-neutral-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
                        <div className="mx-auto w-full max-w-5xl px-6 py-16">
                            <h2 className="mb-10 text-center text-2xl font-semibold">
                                {t('website.how.title')}
                            </h2>
                            <ol className="grid gap-8 sm:grid-cols-1 lg:grid-cols-4">
                                {steps.map((step, index) => (
                                    <li
                                        key={step.key}
                                        className="flex flex-col gap-2"
                                    >
                                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-full bg-neutral-900 text-sm font-semibold text-white dark:bg-neutral-100 dark:text-neutral-900">
                                            {index + 1}
                                        </span>
                                        <h3 className="mt-2 font-semibold">
                                            {step.title}
                                        </h3>
                                        <p className="text-sm leading-relaxed text-neutral-600 dark:text-neutral-400">
                                            {step.body}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </div>
                    </section>
                </main>

                <footer className="border-t border-neutral-200 dark:border-neutral-800">
                    <div className="mx-auto w-full max-w-5xl px-6 py-8 text-center text-sm text-neutral-500 dark:text-neutral-500">
                        {t('website.footer')}
                    </div>
                </footer>
            </div>
        </>
    );
}

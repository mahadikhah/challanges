import { useCallback, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';

import { ApiError, createChallenge, fetchCreateOptions } from '../api';
import { localization, t } from '../localization';
import { webApp } from '../telegram';
import type {
    CreateChallengePayload,
    CreateOptions,
    LabeledEnum,
} from '../types';

/**
 * Creating a challenge without leaving the Mini App.
 *
 * **One question per step**, in the bot wizard's own order — title, then
 * description, then the cadence, the timezone, the start day, how many periods,
 * how it is scored, how it is proven, who can see it, and a read-back before
 * the tap. The order is not decoration: the timezone is asked *before* the
 * start date because "starting on the 3rd" is not an instant until the zone is
 * known, and the two are checked against each other on the way through. The
 * wizard asks them in that order for the same reason, and this screen follows
 * it so the two surfaces cannot teach different habits.
 *
 * **Every bound is read, never retyped.** The pickers are the `{value,label}`
 * lists the options endpoint serves, the character limits are `limits`, the
 * media ceilings are `media_caps`, and the slot count on the summary is
 * `slots`. Nothing here knows what a period type is called or how long a
 * recording may be — an admin who lowers a ceiling sees this form clamp the day
 * they lower it.
 *
 * Two things bypass the server's *floor* and are worth naming, because they are
 * the only copies of a server rule on this screen. The client-side checks
 * (a title of three characters, a start date not in the past, a cap within the
 * ceiling) are the friendly half of rules the server enforces anyway — they
 * exist so the creator is told on the step they are standing on rather than
 * after the submit. And `RECORDING_PROOFS` below restates
 * `ProofType::expectsDuration()`, which a JSON payload cannot carry.
 *
 * The submit is the same `CreateChallenge` action the bot's wizard and the
 * admin panel call: the same slot spend, inside the same transaction, with the
 * announcement dispatched after it commits. A refusal is a reason this screen
 * turns into a sentence in the reader's own locale — the API stays
 * locale-free — and creating does not enrol the creator, so the challenge lands
 * in the list with a "Not joined" badge rather than a streak.
 */

/**
 * The proof types that record, which is `ProofType::expectsDuration()` restated
 * on this side of the wire. It has to be a copy: a Laravel rule cannot ask a PHP
 * enum, and an enum cannot be sent to a browser, so the options payload states
 * *how long* a recording may be and this states *which* types record. A third
 * recording type would have to be added in both places, and the server's rule is
 * the one that decides.
 */
const RECORDING_PROOFS: readonly string[] = [
    'voice_approval',
    'video_approval',
];

type Step =
    | 'title'
    | 'description'
    | 'period_type'
    | 'custom_period_days'
    | 'timezone'
    | 'starts_at'
    | 'total_periods'
    | 'scoring_type'
    | 'scoring'
    | 'proof_type'
    | 'media_caps'
    | 'visibility'
    | 'summary';

/**
 * The question each step asks, which is also the step's heading. A step whose
 * inputs are all one question (the scoring block, the two media caps) reuses the
 * heading of the field that opens it rather than inventing a second prompt.
 */
const QUESTION: Record<Step, string> = {
    title: 'miniapp.create.title',
    description: 'miniapp.create.description',
    period_type: 'miniapp.create.period_type',
    custom_period_days: 'miniapp.create.custom_period_days',
    timezone: 'miniapp.create.timezone',
    starts_at: 'miniapp.create.starts_at',
    total_periods: 'miniapp.create.total_periods',
    scoring_type: 'miniapp.create.scoring_type',
    scoring: 'miniapp.create.target_value',
    proof_type: 'miniapp.create.proof_type',
    media_caps: 'miniapp.create.media_caps',
    visibility: 'miniapp.create.visibility',
    summary: 'miniapp.create.summary',
};

/**
 * The answers, held as the strings the inputs produce. Converting at the edge —
 * once, in `payloadOf()` — keeps every step's check working on the same shape
 * the creator typed, rather than on a number that has already lost the "was it
 * blank?" question.
 */
type Draft = {
    title: string;
    description: string;
    periodType: string;
    customPeriodDays: string;
    timezone: string;
    startsAt: string;
    totalPeriods: string;
    scoringType: string;
    targetValue: string;
    unitLabel: string;
    basePoints: string;
    partial: boolean;
    proofType: string;
    mediaSeconds: string;
    mediaSizeKb: string;
    visibility: string;
};

/**
 * A refusal, in the three shapes this screen can answer. Same split the detail
 * screen keeps: a sentence, a gate with a way through it, and the one refusal
 * that is a quote rather than a wall.
 */
type Notice =
    | { kind: 'error'; text: string }
    | { kind: 'gate'; text: string; joinUrl: string | null }
    | { kind: 'price'; price: number; balance: number };

type OptionsState =
    | { status: 'loading' }
    | { status: 'ready'; options: CreateOptions }
    | { status: 'failed' };

export function CreateChallenge({ onCreated }: { onCreated: () => void }) {
    const [state, setState] = useState<OptionsState>({ status: 'loading' });

    /**
     * The options, fetched when this screen opens rather than at boot: the
     * payload carries every IANA timezone, and most opens never ask for it.
     *
     * Separate from the retry below because an effect must not set state
     * synchronously — the initial value is already `loading`, so the first pass
     * only has to start the request.
     */
    const load = useCallback(() => {
        fetchCreateOptions().then(
            (options) => setState({ status: 'ready', options }),
            () => setState({ status: 'failed' }),
        );
    }, []);

    const retry = useCallback(() => {
        setState({ status: 'loading' });
        load();
    }, [load]);

    useEffect(() => {
        load();
    }, [load]);

    if (state.status === 'loading') {
        return (
            <p className="pt-16 text-center text-[var(--tg-hint)]">
                {t('miniapp.create.loading')}
            </p>
        );
    }

    // A wall rather than a hint: every bound the form enforces comes from this
    // payload, so there is no form to draw without it.
    if (state.status === 'failed') {
        return (
            <div className="pt-16 text-center">
                <p className="text-sm text-[var(--tg-hint)]">
                    {t('miniapp.create.load_failed')}
                </p>

                <button
                    type="button"
                    onClick={retry}
                    className="mt-4 rounded-full bg-[var(--tg-button)] px-4 py-1.5 text-sm font-medium text-[var(--tg-button-text)]"
                >
                    {t('miniapp.create.retry')}
                </button>
            </div>
        );
    }

    return <Form options={state.options} onCreated={onCreated} />;
}

/**
 * The form proper, split out so the options are non-null for its whole life —
 * the alternative is a `null` check inside every step, and hooks cannot be
 * called conditionally anyway.
 */
function Form({
    options,
    onCreated,
}: {
    options: CreateOptions;
    onCreated: () => void;
}) {
    const [draft, setDraft] = useState<Draft>(() => initialDraft(options));
    const [step, setStep] = useState<Step>('title');
    const [pending, setPending] = useState(false);
    const [notice, setNotice] = useState<Notice | null>(null);

    // Recomputed rather than fixed, because two answers add steps of their own:
    // a custom cadence asks how many days, and a recording proof asks for its
    // limits. Going back and changing either takes the step away again — and
    // `index` is clamped, so a step that has just ceased to exist reads as the
    // first one rather than as -1.
    const steps = useMemo(() => stepsFor(draft), [draft]);
    const index = Math.max(0, steps.indexOf(step));

    const edit = useCallback((patch: Partial<Draft>) => {
        setDraft((current) => ({ ...current, ...patch }));
    }, []);

    const next = useCallback(() => {
        const failure = check(step, draft, options);

        if (failure !== null) {
            setNotice({ kind: 'error', text: failure });

            return;
        }

        const following = steps[index + 1];

        if (following === undefined) {
            return;
        }

        setNotice(null);
        setStep(following);
    }, [draft, index, options, step, steps]);

    const back = useCallback(() => {
        const previous = steps[index - 1];

        if (previous === undefined) {
            return;
        }

        setNotice(null);
        setStep(previous);
    }, [index, steps]);

    const submit = useCallback(async () => {
        if (pending) {
            return;
        }

        setPending(true);
        setNotice(null);

        try {
            await createChallenge(payloadOf(draft));

            webApp()?.HapticFeedback?.notificationOccurred('success');

            // Straight back to the list, with no success banner: the list is
            // refetched, so the confirmation is the challenge standing in it —
            // and the create-slot it just spent is one lower in the header.
            // Deliberately no `setPending(false)`; this screen is being
            // replaced, and a last write to a screen nobody is looking at is
            // how a stray frame gets painted.
            onCreated();
        } catch (error) {
            webApp()?.HapticFeedback?.notificationOccurred('error');

            setPending(false);
            setNotice(refusalFrom(error));
        }
    }, [draft, onCreated, pending]);

    return (
        <div className="flex flex-col gap-4">
            <header>
                <p className="text-xs text-[var(--tg-hint)]">
                    {t('miniapp.create.step', {
                        index: index + 1,
                        total: steps.length,
                    })}
                </p>

                <h1 className="mt-1 text-lg leading-snug font-bold">
                    {t(QUESTION[step])}
                </h1>
            </header>

            {notice !== null ? <NoticeBanner notice={notice} /> : null}

            {step === 'title' ? (
                <TextInput
                    value={draft.title}
                    onChange={(title) => edit({ title })}
                    placeholder={t('miniapp.create.title_placeholder')}
                    maxLength={options.limits.title_max}
                    disabled={pending}
                />
            ) : null}

            {step === 'description' ? (
                <div>
                    <TextInput
                        value={draft.description}
                        onChange={(description) => edit({ description })}
                        placeholder={t(
                            'miniapp.create.description_placeholder',
                        )}
                        maxLength={options.limits.description_max}
                        disabled={pending}
                    />

                    <Hint>{t('miniapp.create.description_optional')}</Hint>
                </div>
            ) : null}

            {step === 'period_type' ? (
                <Choices
                    options={options.period_types}
                    value={draft.periodType}
                    onChange={(periodType) => edit({ periodType })}
                    disabled={pending}
                />
            ) : null}

            {step === 'custom_period_days' ? (
                <div>
                    <TextInput
                        value={draft.customPeriodDays}
                        onChange={(customPeriodDays) =>
                            edit({ customPeriodDays })
                        }
                        inputMode="numeric"
                        dir="ltr"
                        disabled={pending}
                    />

                    <Hint>
                        {t('miniapp.create.custom_period_days_hint', {
                            max: options.limits.custom_period_days_max,
                        })}
                    </Hint>
                </div>
            ) : null}

            {step === 'timezone' ? (
                <div>
                    <select
                        value={draft.timezone}
                        onChange={(event) => {
                            const timezone = event.target.value;

                            edit({
                                timezone,
                                // "Today" moves with the zone, so a date the new
                                // zone has already passed is replaced — and a
                                // date still ahead in it is the creator's own
                                // answer, which changing the zone is no reason
                                // to overwrite.
                                startsAt:
                                    draft.startsAt < todayIn(timezone)
                                        ? todayIn(timezone)
                                        : draft.startsAt,
                            });
                        }}
                        disabled={pending}
                        dir="ltr"
                        className="w-full rounded-xl border border-[var(--tg-separator)] bg-[var(--tg-section-bg)] px-3 py-2 text-base outline-none focus:border-[var(--tg-button)]"
                    >
                        {options.timezones.map((zone) => (
                            <option key={zone} value={zone}>
                                {zone}
                            </option>
                        ))}
                    </select>

                    <Hint>{t('miniapp.create.timezone_hint')}</Hint>
                </div>
            ) : null}

            {step === 'starts_at' ? (
                <div>
                    <TextInput
                        type="date"
                        value={draft.startsAt}
                        onChange={(startsAt) => edit({ startsAt })}
                        min={todayIn(draft.timezone)}
                        dir="ltr"
                        disabled={pending}
                    />

                    <Hint>{t('miniapp.create.starts_at_hint')}</Hint>
                </div>
            ) : null}

            {step === 'total_periods' ? (
                <div>
                    <TextInput
                        value={draft.totalPeriods}
                        onChange={(totalPeriods) => edit({ totalPeriods })}
                        inputMode="numeric"
                        dir="ltr"
                        disabled={pending}
                    />

                    <Hint>
                        {t('miniapp.create.total_periods_hint', {
                            max: options.limits.total_periods_max,
                        })}
                    </Hint>
                </div>
            ) : null}

            {step === 'scoring_type' ? (
                <Choices
                    options={options.scoring_types}
                    value={draft.scoringType}
                    onChange={(scoringType) => edit({ scoringType })}
                    disabled={pending}
                />
            ) : null}

            {step === 'scoring' ? (
                <div className="flex flex-col gap-3">
                    <Labelled label={t('miniapp.create.target_value')}>
                        <TextInput
                            value={draft.targetValue}
                            onChange={(targetValue) => edit({ targetValue })}
                            inputMode="decimal"
                            dir="ltr"
                            disabled={pending}
                        />
                    </Labelled>

                    <Labelled label={t('miniapp.create.unit_label')}>
                        <TextInput
                            value={draft.unitLabel}
                            onChange={(unitLabel) => edit({ unitLabel })}
                            placeholder={t(
                                'miniapp.create.unit_label_placeholder',
                            )}
                            maxLength={options.limits.unit_label_max}
                            disabled={pending}
                        />
                    </Labelled>

                    <Labelled label={t('miniapp.create.base_points')}>
                        <TextInput
                            value={draft.basePoints}
                            onChange={(basePoints) => edit({ basePoints })}
                            inputMode="decimal"
                            dir="ltr"
                            disabled={pending}
                        />
                    </Labelled>

                    <label className="flex items-center gap-3 rounded-2xl bg-[var(--tg-section-bg)] p-4 text-sm">
                        <input
                            type="checkbox"
                            checked={draft.partial}
                            onChange={(event) =>
                                edit({ partial: event.target.checked })
                            }
                            disabled={pending}
                            className="size-5 accent-[var(--tg-button)]"
                        />

                        <span>{t('miniapp.create.partial')}</span>
                    </label>
                </div>
            ) : null}

            {step === 'proof_type' ? (
                <div>
                    <Choices
                        options={options.proof_types}
                        value={draft.proofType}
                        onChange={(proofType) => edit({ proofType })}
                        disabled={pending}
                    />

                    <Hint>{t('miniapp.create.proof_type_hint')}</Hint>
                </div>
            ) : null}

            {step === 'media_caps' ? (
                <div className="flex flex-col gap-3">
                    <Labelled label={t('miniapp.create.media_seconds')}>
                        <TextInput
                            value={draft.mediaSeconds}
                            onChange={(mediaSeconds) => edit({ mediaSeconds })}
                            inputMode="numeric"
                            dir="ltr"
                            disabled={pending}
                        />
                    </Labelled>

                    <Labelled label={t('miniapp.create.media_size_kb')}>
                        <TextInput
                            value={draft.mediaSizeKb}
                            onChange={(mediaSizeKb) => edit({ mediaSizeKb })}
                            inputMode="numeric"
                            dir="ltr"
                            disabled={pending}
                        />
                    </Labelled>

                    {/* The ceiling, said as well as enforced: a creator who
                        types 600 into a 300-second limit should learn the
                        number here rather than from a refusal. */}
                    <Hint>
                        {t('miniapp.create.media_hint', {
                            seconds: options.media_caps.max_seconds,
                            size: options.media_caps.max_size_kb,
                        })}
                    </Hint>
                </div>
            ) : null}

            {step === 'visibility' ? (
                <div>
                    <Choices
                        options={options.visibilities}
                        value={draft.visibility}
                        onChange={(visibility) => edit({ visibility })}
                        disabled={pending}
                    />

                    <Hint>{t('miniapp.create.visibility_hint')}</Hint>
                </div>
            ) : null}

            {step === 'summary' ? (
                <Summary draft={draft} options={options} />
            ) : null}

            <div className="flex items-center gap-2">
                {index > 0 ? (
                    <button
                        type="button"
                        onClick={back}
                        disabled={pending}
                        className="rounded-full border border-[var(--tg-separator)] px-4 py-2 text-sm font-medium disabled:opacity-50"
                    >
                        {t('miniapp.create.back')}
                    </button>
                ) : null}

                <button
                    type="button"
                    onClick={step === 'summary' ? submit : next}
                    disabled={pending}
                    className="flex-1 rounded-full bg-[var(--tg-button)] px-4 py-2 text-sm font-medium text-[var(--tg-button-text)] disabled:opacity-50"
                >
                    {primaryLabel(step, pending)}
                </button>
            </div>
        </div>
    );
}

/**
 * The read-back, every row a fact the server will act on.
 *
 * It repeats the answers rather than summarising them, and it names the slot the
 * submit is about to spend — the cost belongs before the tap, not in the refusal
 * after it. The two labels that are enum labels (the cadence, the proof, the
 * visibility) are read off the options payload, so the summary is in the same
 * words as the step that chose them.
 */
function Summary({ draft, options }: { draft: Draft; options: CreateOptions }) {
    const flow = options.flow_types[0];
    const slots = options.slots.available_create_slots;

    return (
        <div className="rounded-2xl bg-[var(--tg-section-bg)] p-4">
            <p className="text-base leading-snug font-semibold">
                {draft.title}
            </p>

            {draft.description !== '' ? (
                <p className="mt-1 text-sm text-[var(--tg-hint)]">
                    {draft.description}
                </p>
            ) : null}

            <ul className="mt-3 flex flex-col gap-2 text-sm">
                {flow !== undefined ? (
                    <Row
                        label={t('miniapp.create.summary_flow', {
                            flow: flow.label,
                        })}
                    />
                ) : null}

                <Row
                    label={t('miniapp.create.summary_timezone', {
                        zone: draft.timezone,
                    })}
                />
                <Row
                    label={t('miniapp.create.summary_starts', {
                        date: formatDay(draft.startsAt),
                    })}
                />

                {/* The cadence, which is the one answer the summary would
                    otherwise only imply — a daily and a monthly challenge of
                    thirty periods read identically without it. */}
                <Row label={labelOf(options.period_types, draft.periodType)} />

                {draft.periodType === 'custom' ? (
                    <Row
                        label={t('miniapp.create.summary_custom_days', {
                            count: draft.customPeriodDays,
                        })}
                    />
                ) : null}

                <Row
                    label={t('miniapp.create.summary_periods', {
                        count: draft.totalPeriods,
                    })}
                />

                {/* Not the creator's answer — nobody is asked — but the number
                    their participants start with, read off the same setting the
                    action will use. */}
                <Row
                    label={t('miniapp.create.summary_freezes', {
                        count: options.default_freezes,
                    })}
                />

                {draft.scoringType === 'quantity' ? (
                    <>
                        <Row
                            label={t('miniapp.create.summary_target', {
                                value: draft.targetValue,
                                unit: draft.unitLabel,
                            })}
                        />
                        <Row
                            label={t('miniapp.create.summary_points', {
                                points: draft.basePoints,
                            })}
                        />

                        {draft.partial ? (
                            <Row label={t('miniapp.create.summary_partial')} />
                        ) : null}
                    </>
                ) : null}

                <Row
                    label={`${labelOf(options.proof_types, draft.proofType)} · ${labelOf(options.visibilities, draft.visibility)}`}
                />

                {RECORDING_PROOFS.includes(draft.proofType) ? (
                    <Row
                        label={t('miniapp.create.summary_media', {
                            seconds: draft.mediaSeconds,
                            size: draft.mediaSizeKb,
                        })}
                    />
                ) : null}

                <Row
                    label={
                        slots > 0
                            ? t('miniapp.create.summary_slot', { slots })
                            : t('miniapp.create.summary_no_slot')
                    }
                />
            </ul>
        </div>
    );
}

/**
 * The steps this draft actually has. Two answers add one each — a custom cadence
 * asks how many days, a recording proof asks for its limits — and the list is
 * rebuilt on every render so going back and changing either answer takes the
 * step away again rather than leaving a question about a field nothing will
 * read.
 */
function stepsFor(draft: Draft): Step[] {
    const steps: Step[] = ['title', 'description', 'period_type'];

    if (draft.periodType === 'custom') {
        steps.push('custom_period_days');
    }

    steps.push('timezone', 'starts_at', 'total_periods', 'scoring_type');

    if (draft.scoringType === 'quantity') {
        steps.push('scoring');
    }

    steps.push('proof_type');

    if (RECORDING_PROOFS.includes(draft.proofType)) {
        steps.push('media_caps');
    }

    steps.push('visibility', 'summary');

    return steps;
}

/**
 * The friendly half of the validation, run on the step the creator is standing
 * on rather than at the submit. `null` means the step is satisfied; anything
 * else is the sentence to show.
 *
 * Every check here is one the server also makes — the bounds come from
 * `limits()`, the ceiling from the settings — so this cannot pass something the
 * API would refuse. It exists so the correction happens on the question that
 * caused it.
 */
function check(
    step: Step,
    draft: Draft,
    options: CreateOptions,
): string | null {
    switch (step) {
        case 'title':
            return draft.title.trim().length < 3
                ? t('miniapp.create.title_too_short', { count: 3 })
                : null;

        case 'custom_period_days':
            return wholeNumber(
                draft.customPeriodDays,
                1,
                options.limits.custom_period_days_max,
            );

        case 'starts_at':
            if (draft.startsAt === '') {
                return t('miniapp.create.starts_at_missing');
            }

            return draft.startsAt < todayIn(draft.timezone)
                ? t('miniapp.create.starts_at_past')
                : null;

        case 'total_periods':
            return wholeNumber(
                draft.totalPeriods,
                1,
                options.limits.total_periods_max,
            );

        case 'scoring': {
            const target = positiveNumber(draft.targetValue);

            if (target !== null) {
                return target;
            }

            if (draft.unitLabel.trim() === '') {
                return t('miniapp.create.unit_required');
            }

            return positiveNumber(draft.basePoints);
        }

        case 'media_caps': {
            const seconds = wholeNumber(
                draft.mediaSeconds,
                1,
                options.media_caps.max_seconds,
            );

            if (seconds !== null) {
                return seconds;
            }

            return wholeNumber(
                draft.mediaSizeKb,
                1,
                options.media_caps.max_size_kb,
            );
        }

        default:
            return null;
    }
}

/**
 * The draft as the API takes it.
 *
 * Three blocks are sent only where they mean something, and that is the mirror
 * of what the action does with a stray one — it *drops* a day count or a media
 * cap left behind by a changed answer, but *refuses* a scoring field on a binary
 * challenge. So omitting them here is not politeness: sending `target_value`
 * alongside `scoring_type: binary` would be a 422, and the request is right to
 * make it one.
 */
function payloadOf(draft: Draft): CreateChallengePayload {
    const payload: CreateChallengePayload = {
        title: draft.title.trim(),
        period_type: draft.periodType,
        timezone: draft.timezone,
        starts_at: draft.startsAt,
        total_periods: Number(draft.totalPeriods),
        proof_type: draft.proofType,
        visibility: draft.visibility,
        scoring_type: draft.scoringType,
    };

    const description = draft.description.trim();

    if (description !== '') {
        payload.description = description;
    }

    if (draft.periodType === 'custom') {
        payload.custom_period_days = Number(draft.customPeriodDays);
    }

    if (draft.scoringType === 'quantity') {
        payload.target_value = Number(draft.targetValue);
        payload.unit_label = draft.unitLabel.trim();
        payload.base_points = Number(draft.basePoints);
        payload.quantity_partial_counts_as_done = draft.partial;
    }

    if (RECORDING_PROOFS.includes(draft.proofType)) {
        payload.proof_media_max_seconds = Number(draft.mediaSeconds);
        payload.proof_media_max_size_kb = Number(draft.mediaSizeKb);
    }

    return payload;
}

/**
 * The refusal the server just sent, as the banner that answers it.
 *
 * The two refusals that are more than a wall get their own shapes: the access
 * gate, with the link that clears it, and `no_create_slot`, which is a quote —
 * the price read at the tap and the balance behind it, so the banner can say how
 * short the reader is rather than stopping at "no".
 *
 * A reason the catalogue has no sentence for (a new refusal this build has not
 * met, or a 422 with no reason at all) degrades to the generic line rather than
 * showing a raw key. Same rule the check-in and join paths keep.
 */
function refusalFrom(error: unknown): Notice {
    if (!(error instanceof ApiError) || error.reason === null) {
        return { kind: 'error', text: t('miniapp.create.unexpected') };
    }

    if (error.reason === 'channel_gate') {
        return {
            kind: 'gate',
            text: t('miniapp.create.gate'),
            joinUrl: error.joinUrl,
        };
    }

    if (
        error.reason === 'no_create_slot' &&
        error.price !== null &&
        error.balance !== null
    ) {
        return {
            kind: 'price',
            price: error.price,
            balance: error.balance,
        };
    }

    const key = `miniapp.create.${error.reason}`;
    const sentence = t(key);

    return {
        kind: 'error',
        text: sentence === key ? t('miniapp.create.unexpected') : sentence,
    };
}

/**
 * The draft as it opens: the first case of each picker, the browser's own
 * timezone when the API knows it, and today's date in that zone.
 *
 * Defaults rather than blanks, because these four are the questions with an
 * obvious answer for almost everybody — and every one of them can be changed on
 * its own step. The two that cannot have a sensible default (how many periods,
 * and what the challenge is called) stay blank.
 */
function initialDraft(options: CreateOptions): Draft {
    const timezone = defaultTimezone(options.timezones);

    return {
        title: '',
        description: '',
        periodType: options.period_types[0].value,
        customPeriodDays: '',
        timezone,
        startsAt: todayIn(timezone),
        totalPeriods: '',
        scoringType: options.scoring_types[0].value,
        targetValue: '',
        unitLabel: '',
        basePoints: '',
        partial: false,
        proofType: options.proof_types[0].value,
        mediaSeconds: '',
        mediaSizeKb: '',
        visibility: options.visibilities[0].value,
    };
}

function defaultTimezone(timezones: string[]): string {
    const local = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return timezones.includes(local) ? local : 'UTC';
}

/**
 * Today, as `Y-m-d`, **in the given zone**.
 *
 * The date is the one the creator is living in, not the one the server is: a
 * user in Auckland opening the form at 9am is on a day UTC has not reached yet,
 * and seeding them with yesterday would have the form refuse its own default.
 * The server reads the same date in the same zone before it stores it as UTC.
 */
function todayIn(timezone: string): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());

    const part = (type: string): string =>
        parts.find((entry) => entry.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}`;
}

/**
 * A `Y-m-d` day in the reader's own words.
 *
 * Anchored at noon UTC on purpose. `new Date('2026-09-17')` is midnight UTC, and
 * formatting *that* in a zone behind UTC names the day before — the exact
 * off-by-one the whole ISO-string discipline exists to avoid. Noon is safe in
 * every offset, and `timeZone: 'UTC'` keeps the reader's own zone out of it
 * entirely: the day is the challenge's, not theirs.
 */
function formatDay(date: string): string {
    return new Intl.DateTimeFormat(localization.locale, {
        dateStyle: 'long',
        timeZone: 'UTC',
    }).format(new Date(`${date}T12:00:00Z`));
}

function labelOf(list: LabeledEnum[], value: string): string {
    return list.find((entry) => entry.value === value)?.label ?? value;
}

/** A whole number within bounds, or the sentence that says why not. */
function wholeNumber(value: string, min: number, max: number): string | null {
    const trimmed = value.trim();

    if (trimmed === '') {
        return t('miniapp.create.number_required');
    }

    const parsed = Number(trimmed);

    if (!Number.isInteger(parsed) || parsed < min || parsed > max) {
        return t('miniapp.create.number_range', { min, max });
    }

    return null;
}

/**
 * A number above zero. Separate from `wholeNumber` because a target of 12.5
 * pushups is a legitimate challenge and a period count of 12.5 is not — the
 * server draws the same line, with `numeric|gt:0` on one and `integer|min:1` on
 * the other.
 */
function positiveNumber(value: string): string | null {
    const trimmed = value.trim();

    if (trimmed === '') {
        return t('miniapp.create.number_required');
    }

    const parsed = Number(trimmed);

    return Number.isFinite(parsed) && parsed > 0
        ? null
        : t('miniapp.create.number_positive');
}

function primaryLabel(step: Step, pending: boolean): string {
    if (step !== 'summary') {
        return t('miniapp.create.next');
    }

    return t(pending ? 'miniapp.create.working' : 'miniapp.create.submit');
}

function TextInput({
    value,
    onChange,
    type = 'text',
    inputMode,
    placeholder,
    maxLength,
    min,
    dir,
    disabled,
}: {
    value: string;
    onChange: (value: string) => void;
    type?: 'text' | 'date';
    inputMode?: 'numeric' | 'decimal';
    placeholder?: string;
    maxLength?: number;
    min?: string;
    dir?: 'ltr';
    disabled: boolean;
}) {
    return (
        <input
            type={type}
            value={value}
            onChange={(event) => onChange(event.target.value)}
            inputMode={inputMode}
            placeholder={placeholder}
            maxLength={maxLength}
            min={min}
            dir={dir}
            disabled={disabled}
            className="w-full rounded-xl border border-[var(--tg-separator)] bg-transparent px-3 py-2 text-base outline-none focus:border-[var(--tg-button)]"
        />
    );
}

/**
 * One picker, one row per choice.
 *
 * Rows rather than a strip of chips, because several of these labels are
 * sentences — "Voice message, approved by the creator" is not a chip — and a
 * row is also the honest size of a tap target on a phone.
 */
function Choices({
    options,
    value,
    onChange,
    disabled,
}: {
    options: LabeledEnum[];
    value: string;
    onChange: (value: string) => void;
    disabled: boolean;
}) {
    return (
        <div className="flex flex-col gap-2">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    onClick={() => onChange(option.value)}
                    disabled={disabled}
                    className={`rounded-xl px-4 py-2.5 text-start text-sm disabled:opacity-50 ${
                        option.value === value
                            ? 'bg-[var(--tg-button)] font-medium text-[var(--tg-button-text)]'
                            : 'bg-[var(--tg-secondary-bg)]'
                    }`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function Labelled({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="block">
            <span className="text-sm font-semibold">{label}</span>

            <span className="mt-1 block">{children}</span>
        </label>
    );
}

function Hint({ children }: { children: ReactNode }) {
    return <p className="mt-2 text-xs text-[var(--tg-hint)]">{children}</p>;
}

/**
 * One line of the read-back. A sentence rather than a label/value pair — "Starts
 * on 3 Mehr" reads in one direction in Farsi and the other in English, and a
 * two-column layout would have to pick one.
 */
function Row({ label }: { label: string }) {
    return <li className="text-[var(--tg-hint)]">{label}</li>;
}

function NoticeBanner({ notice }: { notice: Notice }) {
    if (notice.kind === 'gate') {
        // Narrowed once: TS will not keep `notice.joinUrl` non-null through the
        // closure below, but a const it will.
        const { joinUrl } = notice;
        const app = webApp();

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
    // the price — out of *slots* is not out of *money*, and the sentence would
    // otherwise tell a reader holding a thousand coins they are 0 coins short.
    if (notice.kind === 'price') {
        const short = notice.price - notice.balance;

        return (
            <div className="rounded-xl bg-red-500/10 px-4 py-3 text-sm text-red-600">
                <p>{t('miniapp.create.no_slot')}</p>

                <p className="mt-1">
                    {t('miniapp.create.price', {
                        price: notice.price,
                        balance: notice.balance,
                    })}
                </p>

                {short > 0 ? (
                    <p className="mt-1 font-medium">
                        {t('miniapp.create.short', { coins: short })}
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

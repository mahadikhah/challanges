/**
 * The shapes `/api/v1/miniapp/*` returns, mirroring the PHP resources exactly
 * (`MiniApp/AuthController`, `MeController`, `MiniAppUserResource`,
 * `ChallengeResource`, `CheckInController`). The API is versioned, so these
 * types are a contract: when a field changes shape, it changes here in the same
 * commit.
 *
 * `MiniAppUser` is the one shape written down twice on purpose — here and in
 * `MiniAppUserResource`. It used to be written down twice on the *server*, in
 * `AuthController` and `MeController` separately, and `/auth`'s copy had
 * already lost `coins` while this type still demanded it. `api.ts` casts with
 * `as T` and validates nothing, so TypeScript never saw the disagreement: the
 * header simply rendered `String(undefined)`.
 */

/** An enum as the API states it: machine value plus translated label. */
export type LabeledEnum = {
    value: string;
    label: string;
};

export type MiniAppUser = {
    id: number;
    first_name: string;
    username: string | null;
    /**
     * The language the user chose, or null when they never have. The screen
     * itself renders in `localization.locale`, which the server resolves from
     * this preference and falls back from — so null is a normal state, not an
     * error, and a client that needs a locale should use that one.
     */
    locale: string | null;
    coins: number;
    /**
     * Where the bot a proof lives in can be opened from. Null when the user
     * carries no platform or the platform's username is not configured; the
     * screen then falls back to directing the reader through the bot rather
     * than opening a link that could not exist.
     */
    bot_url: string | null;
};

export type AuthSession = {
    token: string;
    token_type: string;
    expires_at: string;
    user: MiniAppUser;
};

export type ChallengeMe = {
    status: LabeledEnum;
    current_streak: number;
    longest_streak: number;
    total_score: number;
    joined_period_index: number;
    freezes: {
        total: number;
        used: number;
        remaining: number;
    };
};

/**
 * A check-in's state as the API states it. On a quantity challenge the
 * reported number and the score it earned ride along — null until the row is
 * scored. A typed-proof challenge carries its phrase while (and only while)
 * the participant still owes the period: the current period on the detail
 * surface, never a list row, never another participant's, never a settled one.
 */
export type CheckInView = LabeledEnum & {
    expected_phrase?: string | null;
    reported_value?: number | null;
    score?: number | null;
};

/** The quantity scoring design; null on a binary challenge. */
export type ScoringView = {
    target_value: number;
    unit_label: string;
    base_points: number;
    partial_counts_as_done: boolean;
};

export type CurrentPeriod = {
    index: number;
    starts_at: string;
    ends_at: string;
    owes_check_in: boolean;
    check_in: CheckInView | null;
};

export type HistoryEntry = {
    index: number;
    status: CheckInView;
};

export type ChallengeView = {
    id: number;
    title: string;
    description: string | null;
    status: LabeledEnum;
    period_type: LabeledEnum;
    starts_at: string;
    timezone: string;
    total_periods: number;
    proof_type: LabeledEnum;
    visibility: LabeledEnum;
    is_creator: boolean;
    /**
     * Whether a participation row exists — not whether it is active. Somebody
     * who completed, left or was removed still has one, and `me.status` names
     * which of those they are.
     */
    has_joined: boolean;
    scoring: ScoringView | null;
    /**
     * Null exactly when `has_joined` is false, which is a creator who has not
     * joined their own challenge yet — creating one does not enrol the
     * creator. Never read a null `me` as zeroes: there is no streak to report,
     * and a zeroed grid would claim "you are keeping a 0-day streak" about
     * somebody who is not in the challenge at all.
     */
    me: ChallengeMe | null;
    /**
     * The challenge's own open period, so it is present for a viewer with no
     * participation too — a creator who never joined still needs "period 3 of
     * 30" and when it ends. Only `owes_check_in` and `check_in` belong to a
     * participant, and both go neutral without one.
     */
    current_period: CurrentPeriod | null;
    history: HistoryEntry[];
};

/**
 * A refusal from a Mini App action: the machine-readable reason, plus whatever
 * numbers that particular refusal needs its reader to see.
 *
 * One type for the whole surface rather than one per endpoint, because
 * `api.ts`'s `request()` parses the body before it knows which endpoint
 * answered — the union of every refusal shape it might be handed.
 *
 * `reason` is a `CheckInRejection` or `JoinRejection` value, or `channel_gate`
 * for the access gate; `join_url` rides along only when the gate is what
 * blocked. `price` and `balance` ride along on the join endpoint's
 * `no_join_slot` and the create endpoint's `no_create_slot` — the two refusals
 * that are a quote rather than a wall — and they are what let the screen say
 * how short the reader is rather than just "no".
 */
export type ApiRefusal = {
    reason: string;
    join_url?: string | null;
    price?: number | null;
    balance?: number | null;
};

/**
 * What `GET /challenges/options` serves: everything the create form renders,
 * and the two numbers it quotes before the user commits.
 *
 * Every bound here is the server's own — the action's character limits, the
 * IANA list the action validates against, the admin's media ceilings — so a
 * form built from this payload cannot offer a value the API would then refuse.
 * Nothing about it is hardcoded client-side, and that is the point: an admin
 * who lowers the media cap sees the form clamp the day they lower it.
 */
export type CreateOptions = {
    limits: {
        title_max: number;
        description_max: number;
        total_periods_max: number;
        custom_period_days_max: number;
        approval_criteria_max: number;
        unit_label_max: number;
    };
    period_types: LabeledEnum[];
    proof_types: LabeledEnum[];
    visibilities: LabeledEnum[];
    scoring_types: LabeledEnum[];
    /**
     * Stated, not offered: this build creates `simple` challenges only, and the
     * list has one entry. Task 7 extends it in place rather than reshaping
     * anything here, so the screen reads it instead of assuming.
     */
    flow_types: LabeledEnum[];
    /** Every IANA identifier the API accepts — a full picker, not a shortlist. */
    timezones: string[];
    default_freezes: number;
    media_caps: {
        max_seconds: number;
        max_size_kb: number;
    };
    /**
     * A count and a price, both advisory. The API remains the authority on
     * whether a slot is actually there, and the refusal arrives with the same
     * two numbers read again at the tap.
     */
    slots: {
        available_create_slots: number;
        price: number;
    };
    bot_url: string | null;
};

/**
 * The create form's submission, in the shape `StoreChallengeRequest` accepts.
 *
 * Deliberately absent, and each for a reason: no `flow_type` (this build
 * creates simple challenges, and the API takes no such field), no
 * `proof_is_public` (the bot's wizard does not ask either, and private is the
 * default), no `default_freezes` (null takes the admin's configured default).
 *
 * The scoring block and the media caps are optional on the wire because which
 * of them a challenge needs is a function of two other fields — a binary
 * button challenge sends neither, and sending them anyway would be refused by
 * the same rules that make them required.
 */
export type CreateChallengePayload = {
    title: string;
    description?: string | null;
    period_type: string;
    custom_period_days?: number | null;
    timezone: string;
    /** `Y-m-d`, never a browser-localised string. */
    starts_at: string;
    total_periods: number;
    proof_type: string;
    visibility: string;
    scoring_type: string;
    target_value?: number | null;
    unit_label?: string | null;
    base_points?: number | null;
    quantity_partial_counts_as_done?: boolean | null;
    proof_media_max_seconds?: number | null;
    proof_media_max_size_kb?: number | null;
};

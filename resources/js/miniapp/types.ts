/**
 * The shapes `/api/v1/miniapp/*` returns, mirroring the PHP resources exactly
 * (`MiniApp/AuthController`, `MeController`, `ChallengeResource`,
 * `CheckInController`). The API is versioned, so these types are a contract:
 * when a field changes shape, it changes here in the same commit.
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
    locale: string;
    coins: number;
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
 * scored.
 */
export type CheckInView = LabeledEnum & {
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
    scoring: ScoringView | null;
    me: ChallengeMe;
    current_period: CurrentPeriod | null;
    history: HistoryEntry[];
};

/**
 * A refusal from the check-in endpoint: the machine-readable reason (a
 * `CheckInRejection` value, or `channel_gate` for the access gate) plus the
 * join link when the gate is what blocked.
 */
export type CheckInRefusal = {
    reason: string;
    join_url?: string | null;
};

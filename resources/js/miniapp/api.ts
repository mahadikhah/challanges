import type {
    ApiRefusal,
    AuthSession,
    ChallengeView,
    MiniAppUser,
} from './types';

/**
 * The typed client for `/api/v1/miniapp/*`. No Axios, no interceptors — the
 * Mini App makes a handful of requests, and `fetch` plus a token variable
 * says everything there is to say.
 *
 * The bearer token lives in memory only. Each app open exchanges a fresh
 * initData for a fresh token (per-exchange minting is the server's designed
 * flow), so there is nothing to persist and nothing stale to reuse: closing
 * the Mini App forgets the token entirely.
 */

const BASE = '/api/v1/miniapp';

let token: string | null = null;

/**
 * A failed request: the HTTP status plus whatever reason the API named —
 * `CheckInRejection` and `JoinRejection` values, `channel_gate`, and the join
 * endpoint's `no_join_slot` — arrive as `{ reason }` bodies; anything else
 * (validation, a 500) has no reason and the caller shows the generic sentence.
 *
 * `price` and `balance` are set only by `no_join_slot`: the quote that turns
 * "you have no slot" into an amount the reader can act on. Both are null
 * everywhere else, and a caller that reads them must have matched the reason
 * first.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly reason: string | null,
        public readonly joinUrl: string | null,
        public readonly price: number | null = null,
        public readonly balance: number | null = null,
    ) {
        super(`Mini App API request failed with status ${status}.`);

        this.name = 'ApiError';
    }
}

async function request<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await fetch(`${BASE}${path}`, {
        ...init,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token === null ? {} : { Authorization: `Bearer ${token}` }),
            ...(init?.headers ?? {}),
        },
    });

    if (response.ok) {
        return (await response.json()) as T;
    }

    const body = await response.json().catch(() => null);
    const refusal = body as Partial<ApiRefusal> | null;
    const reason = typeof refusal?.reason === 'string' ? refusal.reason : null;
    const joinUrl =
        typeof refusal?.join_url === 'string' ? refusal.join_url : null;
    // The refusal body is untrusted like any other: `typeof` rather than a
    // cast, so a server that one day sends `"25"` reads as absent instead of
    // reaching `Intl`-shaped arithmetic as a string.
    const price = typeof refusal?.price === 'number' ? refusal.price : null;
    const balance =
        typeof refusal?.balance === 'number' ? refusal.balance : null;

    throw new ApiError(response.status, reason, joinUrl, price, balance);
}

/**
 * Swap the Telegram-signed `initData` for the session's bearer token and the
 * boot user. The token stays module-private on purpose: nothing outside this
 * file can send a request without it, and nothing can read it to leak it.
 */
export async function authenticate(initData: string): Promise<MiniAppUser> {
    const session = await request<AuthSession>('/auth', {
        method: 'POST',
        body: JSON.stringify({ init_data: initData }),
    });

    token = session.token;

    return session.user;
}

export function fetchChallenges(): Promise<ChallengeView[]> {
    return request<{ data: ChallengeView[] }>('/challenges').then(
        (payload) => payload.data,
    );
}

/**
 * The token's own user, re-read on demand.
 *
 * `authenticate()` hands the boot user over once, at the exchange, and the
 * header keeps that number for the rest of the open. Anything that can settle a
 * balance afterwards re-reads it here, so the header is the ledger's answer
 * rather than the snapshot the app booted with.
 *
 * Same replacement-not-a-patch contract as the check-in response: the body is
 * the whole user, not a delta to merge.
 */
export function fetchMe(): Promise<MiniAppUser> {
    return request<{ data: MiniAppUser }>('/me').then(
        (payload) => payload.data,
    );
}

export function fetchChallenge(id: number): Promise<ChallengeView> {
    return request<{ data: ChallengeView }>(`/challenges/${id}`).then(
        (payload) => payload.data,
    );
}

/**
 * Submit a non-media check-in. Resolves with the participant's whole new
 * state — the server's response is the replacement, not a patch to merge.
 *
 * Which fields the proof needs depends on the challenge: a `text_autogen`
 * proof sends `text` (the phrase the server issued for this period), a
 * quantity challenge sends `reportedValue`, and a quantity text challenge
 * sends both in one request. Neither sends anything when neither applies —
 * and a scoring challenge without a number is refused with `value_required`,
 * which is the SPA's cue to show its input.
 */
export function submitCheckIn(
    id: number,
    input?: { reportedValue?: number; text?: string },
): Promise<ChallengeView> {
    const body: { reported_value?: number; text?: string } = {};
    let hasBody = false;

    if (input?.reportedValue !== undefined) {
        body.reported_value = input.reportedValue;
        hasBody = true;
    }

    if (input?.text !== undefined) {
        body.text = input.text;
        hasBody = true;
    }

    return request<{ data: ChallengeView }>(`/challenges/${id}/check-in`, {
        method: 'POST',
        body: hasBody ? JSON.stringify(body) : undefined,
    }).then((payload) => payload.data);
}

/**
 * Join the challenge. Resolves with the viewer's whole new state — the same
 * replacement-not-a-patch contract `submitCheckIn()` has, and the same action
 * the bot's join button calls.
 *
 * No body: the actor is the bearer token's user, and there is nothing else the
 * caller could legitimately supply. Posting it twice is safe and charges one
 * slot — the server answers 201 the first time and 200 after that, which this
 * client does not need to tell apart.
 */
export function joinChallenge(id: number): Promise<ChallengeView> {
    return request<{ data: ChallengeView }>(`/challenges/${id}/join`, {
        method: 'POST',
    }).then((payload) => payload.data);
}

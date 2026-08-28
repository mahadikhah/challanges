import type {
    AuthSession,
    ChallengeView,
    CheckInRefusal,
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
 * `CheckInRejection` values and `channel_gate` arrive as `{ reason }` bodies;
 * anything else (validation, a 500) has no reason and the caller shows the
 * generic sentence.
 */
export class ApiError extends Error {
    constructor(
        public readonly status: number,
        public readonly reason: string | null,
        public readonly joinUrl: string | null,
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
    const refusal = body as Partial<CheckInRefusal> | null;
    const reason = typeof refusal?.reason === 'string' ? refusal.reason : null;
    const joinUrl =
        typeof refusal?.join_url === 'string' ? refusal.join_url : null;

    throw new ApiError(response.status, reason, joinUrl);
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

export function fetchChallenge(id: number): Promise<ChallengeView> {
    return request<{ data: ChallengeView }>(`/challenges/${id}`).then(
        (payload) => payload.data,
    );
}

/**
 * Submit the one-tap check-in. Resolves with the participant's whole new
 * state — the server's response is the replacement, not a patch to merge.
 */
export function submitCheckIn(id: number): Promise<ChallengeView> {
    return request<{ data: ChallengeView }>(`/challenges/${id}/check-in`, {
        method: 'POST',
    }).then((payload) => payload.data);
}

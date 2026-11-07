<?php

namespace App\Actions\MiniApp;

use App\Actions\Telegram\ResolveTelegramUser;
use App\Enums\SettingKey;
use App\Exceptions\InvalidInitDataException;
use App\Services\Settings;
use App\Services\Telegram\InitDataVerifier;
use Carbon\CarbonImmutable;

/**
 * Turn a verified `Telegram.WebApp.initData` into a short-lived Sanctum
 * bearer token.
 *
 * The identity is established **server-side from the signature** — the token in
 * the bot's own hands — and the user row is resolved by `platform_user_id` from that
 * payload, never from anything the client claims about who it is. Every
 * `/api/v1/miniapp/*` request after this re-resolves the actor from the bearer
 * token the same way; a client-supplied id is never consulted.
 *
 * Token minting is deliberately *not* idempotent across calls: each Mini App
 * open exchanges a fresh initData for a fresh token, and the previous token
 * simply ages out. The idempotency that matters here is inside the exchange —
 * one verified initData, one user row (`ResolveTelegramUser`'s
 * firstOrCreate) — not one token forever.
 *
 * The channel gate is not checked here. Authenticating is not a privileged
 * act: the gate guards *using* the platform, and where the Mini App enforces
 * it belongs to the surface that has a join-prompt UX to show, not to the
 * token exchange.
 */
class AuthenticateMiniAppUser
{
    public function __construct(
        private readonly InitDataVerifier $verifier,
        private readonly ResolveTelegramUser $resolveUser,
        private readonly Settings $settings,
    ) {}

    /**
     * @throws InvalidInitDataException when the initData fails
     *                                  verification
     */
    public function handle(string $initData): MiniAppSession
    {
        $verified = $this->verifier->verify($initData);

        $user = $this->resolveUser->handle($verified->user);

        $expiresAt = now()->addMinutes(
            $this->lifetimeMinutes(),
        );

        $token = $user->createToken('miniapp', ['miniapp'], $expiresAt);

        return new MiniAppSession(
            $user,
            $token->plainTextToken,
            CarbonImmutable::instance($expiresAt),
        );
    }

    /**
     * How long the token lives, floored at a minute.
     *
     * `miniapp_token_ttl_minutes` is an admin-editable setting and its form
     * accepts zero, which would mint a token that expired at the instant it was
     * issued. That failure is invisible at the exchange — `/auth` succeeds and
     * hands back a token — and lands on the *next* request instead, as a 401 on
     * `/challenges` that the Mini App used to report as a failed identity check.
     * An operator reading "we could not verify your Telegram identity" while
     * their identity had just been verified has nothing to go on.
     *
     * The floor is here, at minting, rather than in the setting's validation:
     * the value can already have been written by an older form or a seeder, and
     * a token that lasts a minute is a recoverable annoyance where a token that
     * lasts no time at all is a dead app.
     */
    private function lifetimeMinutes(): int
    {
        return max(1, $this->settings->integer(SettingKey::MiniAppTokenTtlMinutes));
    }
}

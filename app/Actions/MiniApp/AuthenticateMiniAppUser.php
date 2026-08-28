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
 * the bot's own hands — and the user row is resolved by `telegram_id` from that
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
            $this->settings->integer(SettingKey::MiniAppTokenTtlMinutes),
        );

        $token = $user->createToken('miniapp', ['miniapp'], $expiresAt);

        return new MiniAppSession(
            $user,
            $token->plainTextToken,
            CarbonImmutable::instance($expiresAt),
        );
    }
}

<?php

namespace App\Services\Telegram;

use App\Enums\SettingKey;
use App\Exceptions\InvalidInitDataException;
use App\Services\Settings;

/**
 * Verifies `Telegram.WebApp.initData` — the one proof of identity the Mini App
 * has, and the one thing standing between a forged request and a bearer token.
 *
 * The argument order is the classic bug, so it is pinned here in the shape
 * CLAUDE.md verified against core.telegram.org:
 *
 * ```
 * secret_key        = HMAC_SHA256(<bot_token>, "WebAppData")   // token is the MESSAGE
 *                     ...as RAW BYTES. Note the outer line below is wrapped in
 *                     hex(...) and this one is not — that difference is the whole
 *                     contract, and dropping it refuses every real user.
 * data_check_string = all received fields EXCEPT hash (and signature),
 *                     sorted alphabetically, formatted key=<value>, joined with \n
 * valid             = hex(HMAC_SHA256(data_check_string, secret_key)) === hash
 * ```
 *
 * The comparison is `hash_equals` — timing-safe, never `==` or `===` on the
 * digests themselves. `signature` (the third-party Ed25519 path) is excluded
 * from the check string and not verified at all: we hold the bot token, so the
 * HMAC path is both sufficient and ours.
 *
 * `auth_date` is checked against a window because Telegram sets no TTL of its
 * own; the window is the admin-tunable `initdata_max_age_seconds` setting, and
 * it applies only to the token *exchange* — once a Sanctum token is issued, its
 * own lifetime governs.
 */
class InitDataVerifier
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Verify a raw initData string and return what it vouches for.
     *
     * @throws InvalidInitDataException when the payload is malformed, the hash
     *                                  disagrees with the contents, or it is stale
     */
    public function verify(string $initData): VerifiedInitData
    {
        $parsed = [];
        parse_str($initData, $parsed);

        // One pass to normalise: every field Telegram sends is a string under
        // a non-numeric name, and anything else is a payload we did not ask
        // for — including the array syntax `parse_str` happily accepts and
        // Telegram never produces, which would leave the check string's shape
        // up to a guess.
        $hash = null;
        $signed = [];

        foreach ($parsed as $key => $value) {
            if (! is_string($key)) {
                throw InvalidInitDataException::malformed('a field name is not a string');
            }

            if ($key === 'hash') {
                if (! is_string($value) || $value === '') {
                    throw InvalidInitDataException::malformed('no hash');
                }

                $hash = $value;

                continue;
            }

            if (! is_string($value)) {
                throw InvalidInitDataException::malformed("field {$key} is not a scalar");
            }

            $signed[$key] = $value;
        }

        if ($hash === null) {
            throw InvalidInitDataException::malformed('no hash');
        }

        ksort($signed);

        // Read before the HMAC, not inside it: an unset `TELEGRAM_BOT_TOKEN`
        // arrives as a null, and `hash_hmac` wants a string — but casting it at
        // the call site would compare against a hash computed from the empty
        // string, which is a *verification* that always fails rather than a
        // refusal that says why. An environment that cannot verify anything is a
        // refusal, and refusals here are one uniform 401.
        //
        // `(string) config(...)`, not `Config::string(...)`: `Config::string`
        // throws on a null, and its default does not rescue it — `Arr::get`
        // returns the stored null because the key exists. That is the 500 this
        // guard exists to prevent.
        $botToken = (string) config('services.telegram.bot_token');

        if ($botToken === '') {
            throw InvalidInitDataException::unverifiable('no bot token is configured');
        }

        $lines = [];

        foreach ($signed as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        $computed = hash_hmac(
            'sha256',
            implode("\n", $lines),
            // Raw digest, not the hex rendering of it — see `InitDataSecretKey`,
            // which is the one definition of this and the place the reasoning lives.
            InitDataSecretKey::derive($botToken),
        );

        // Lowercased first: Telegram's hashes are lowercase hex, but a client
        // round-trip could uppercase them, and `hash_equals` is byte-exact.
        if (! hash_equals($computed, strtolower($hash))) {
            throw InvalidInitDataException::tampered();
        }

        $this->assertFresh($signed);

        $user = json_decode($signed['user'] ?? '', true);

        if (! is_array($user) || ! is_int($user['id'] ?? null) || $user['id'] < 1) {
            // A correctly signed payload still has to name a Telegram user for
            // the platform to have anyone to authenticate.
            throw InvalidInitDataException::malformed('no usable user object');
        }

        unset($signed['user']);

        return new VerifiedInitData($user, $signed);
    }

    /**
     * @param  array<string, string>  $signed
     */
    private function assertFresh(array $signed): void
    {
        $authDate = $signed['auth_date'] ?? null;

        if ($authDate === null || ! ctype_digit($authDate)) {
            throw InvalidInitDataException::malformed('no auth_date');
        }

        $maxAge = $this->settings->integer(SettingKey::InitDataMaxAgeSeconds);

        if ($maxAge > 0) {
            $age = now()->getTimestamp() - (int) $authDate;

            if ($age > $maxAge) {
                throw InvalidInitDataException::outdated($age, $maxAge);
            }
        }
    }
}

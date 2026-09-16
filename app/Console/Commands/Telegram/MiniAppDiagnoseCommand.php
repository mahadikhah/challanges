<?php

namespace App\Console\Commands\Telegram;

use App\Enums\SettingKey;
use App\Exceptions\InvalidInitDataException;
use App\Services\Settings;
use App\Services\Telegram\InitDataVerifier;
use Illuminate\Console\Command;

/**
 * Says why the Mini App cannot get in, from the server side — where the browser
 * cannot look.
 *
 * The Mini App's failure modes are almost all invisible from the client: the
 * BOT TOKEN is server-side, `initData` is signed with it, and a wrong or missing
 * token makes every single verification fail in exactly the same way as a forged
 * one. The screen the user sees cannot tell those apart, and neither can a log
 * line that only records "verification refused". This command answers the
 * question the operator actually has — is this server capable of verifying a
 * payload that is known to be good?
 *
 * **The token is never printed**, not even in part. Its presence and its length
 * are what an operator can act on ("is the variable set at all?", "does this
 * look like the `123456:ABC…` shape Telegram issues?"); its value belongs in one
 * place, and that place is the secret store. The self-test is what replaces
 * printing it: if the token is right, a payload this command signs *with that
 * token* will verify.
 *
 * The self-test **re-implements Telegram's HMAC chain** rather than calling into
 * `InitDataVerifier` for the signing half. A check that signed with the
 * verifier's own internals would pass whatever the verifier did, including being
 * wrong; this one is an independent statement of the same spec, and the two
 * agreeing is the evidence.
 */
class MiniAppDiagnoseCommand extends Command
{
    /** @var string */
    protected $signature = 'telegram:miniapp-diagnose';

    /** @var string */
    protected $description = 'Report what the Mini App needs to boot, and self-test initData verification';

    public function handle(Settings $settings, InitDataVerifier $verifier): int
    {
        // `(string) config(...)` rather than `Config::string(...)`: an unset env
        // var arrives as a null, `Config::string` throws on a null, and its
        // default does not rescue it — `Arr::get` returns the stored null
        // because the key exists. An operator usually runs this command
        // *because* something is unset, so throwing on the way to the answer
        // would be cruel.
        $token = (string) config('services.telegram.bot_token');

        $this->components->twoColumnDetail(
            'Bot token',
            $token === '' ? '<fg=red>not set</>' : 'set ('.strlen($token).' characters)',
        );

        if ($token === '') {
            // Everything below depends on this, so there is nothing useful left
            // to say — and saying it with a green self-test would be worse than
            // saying nothing.
            $this->components->error(
                'TELEGRAM_BOT_TOKEN is empty, so no initData can ever verify. Set it and re-run.',
            );

            return self::FAILURE;
        }

        $miniAppUrl = (string) config('services.telegram.miniapp_url');
        $this->components->twoColumnDetail('MINIAPP_URL', $this->urlVerdict($miniAppUrl));

        if ($miniAppUrl !== '' && ! str_starts_with($miniAppUrl, 'https://')) {
            $this->components->warn(
                'Telegram only hands initData to an HTTPS page, so a plain-HTTP Mini App opens with an empty one.'
            );
        }

        $maxAge = $settings->integer(SettingKey::InitDataMaxAgeSeconds);
        $ttl = $settings->integer(SettingKey::MiniAppTokenTtlMinutes);

        $this->components->twoColumnDetail('auth_date window', $maxAge > 0 ? "{$maxAge} seconds" : 'off (0 — staleness accepted)');

        if ($maxAge <= 0) {
            // Permissive rather than broken, which is exactly why it is worth
            // saying out loud: with the window off, an initData that leaked
            // months ago is still a working credential. That is a real posture
            // decision, and it should be a visible one.
            $this->components->warn(
                'The auth_date window is disabled, so any initData that was ever signed is still accepted.'
            );
        }

        // The floor `AuthenticateMiniAppUser` applies, shown as the value that
        // will actually be used rather than the one on the row.
        $this->components->twoColumnDetail(
            'Token lifetime',
            $ttl >= 1 ? "{$ttl} minutes" : "{$ttl} minutes on the row, floored to 1",
        );

        $accepts = $this->selfTest($verifier, $token);

        if ($accepts !== null) {
            $this->components->error("The verification self-test failed: {$accepts}");

            return self::FAILURE;
        }

        $this->components->info('A payload signed with this token verifies, and a tampered one is refused.');

        return self::SUCCESS;
    }

    /**
     * Sign a synthetic payload with the configured token and put it through the
     * real verifier — twice, because accepting is only half of working.
     *
     * @return string|null the failure, or null when both halves behaved
     */
    private function selfTest(InitDataVerifier $verifier, string $token): ?string
    {
        $payload = $this->sign($token, [
            'auth_date' => (string) now()->getTimestamp(),
            'query_id' => 'AAE-diagnostic-self-test',
            'user' => json_encode(['id' => 1, 'first_name' => 'Self-test'], JSON_THROW_ON_ERROR),
        ]);

        try {
            $verifier->verify($payload);
        } catch (InvalidInitDataException $refused) {
            return "a correctly signed payload was refused ({$refused->getMessage()})";
        }

        try {
            // One character of the hash changed: whatever the verifier is doing,
            // it has to notice this, or it is not checking anything at all.
            $verifier->verify($this->tamper($payload));

            return 'a payload with an altered hash was accepted';
        } catch (InvalidInitDataException) {
            return null;
        }
    }

    /**
     * Telegram's own signing chain, written out.
     *
     * @param  array<string, string>  $fields
     */
    private function sign(string $token, array $fields): string
    {
        ksort($fields);

        $checkString = implode(
            "\n",
            array_map(
                static fn (string $key): string => $key.'='.$fields[$key],
                array_keys($fields),
            ),
        );

        $fields['hash'] = hash_hmac(
            'sha256',
            $checkString,
            hash_hmac('sha256', $token, 'WebAppData'),
        );

        return http_build_query($fields);
    }

    /**
     * The same payload with one hex digit of its hash flipped, which is the
     * cheapest forgery there is and the one a signature check exists to catch.
     */
    private function tamper(string $payload): string
    {
        $parsed = [];
        parse_str($payload, $parsed);

        /** @var string $hash */
        $hash = $parsed['hash'];
        $parsed['hash'] = ($hash[0] === 'a' ? 'b' : 'a').substr($hash, 1);

        return http_build_query($parsed);
    }

    private function urlVerdict(string $url): string
    {
        return match (true) {
            $url === '' => '<fg=yellow>not set</>',
            str_starts_with($url, 'https://') => $url,
            default => "<fg=yellow>{$url} (not https)</>",
        };
    }
}

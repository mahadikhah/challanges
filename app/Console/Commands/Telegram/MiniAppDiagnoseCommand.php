<?php

namespace App\Console\Commands\Telegram;

use App\Enums\SettingKey;
use App\Exceptions\InvalidInitDataException;
use App\Services\Settings;
use App\Services\Telegram\InitDataSecretKey;
use App\Services\Telegram\InitDataVerifier;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramResponseException;
use Telegram\Bot\Exceptions\TelegramSDKException;

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
 *
 * The self-test has one blind spot, though, and it is the one that bites in the
 * field: it signs with whatever token is configured, so it passes just as
 * happily when that token belongs to the **wrong bot**. A token for the wrong
 * bot fails every real user's `initData` in exactly the way a forged one does,
 * and no amount of local cryptography can tell the difference. So the command
 * also *asks Telegram* — which bot this token is, and what button Telegram has
 * actually stored — because those are facts only the other end knows.
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

        // The outward half, deliberately before the self-test: naming the bot is
        // what catches the failure the self-test cannot see, and an operator who
        // learned only that "the HMAC chain is correct" would have learned
        // nothing about why their users still cannot get in.
        $this->reportReachability($miniAppUrl);

        // Resolved here rather than injected, and only now that a token is known
        // to exist. The container's binding for `Api` throws when the token is
        // empty, and method injection would resolve it *before* this body ran —
        // making an unset token crash the command with a stack trace instead of
        // the one-line answer above. A diagnosis command is run precisely when
        // the server is misconfigured; it cannot be the thing that breaks on a
        // misconfigured server.
        $telegram = $this->laravel->make(Api::class);

        if (! $this->reportBotIdentity($telegram)) {
            return self::FAILURE;
        }

        $this->reportMenuButton($telegram, $miniAppUrl);

        $accepts = $this->selfTest($verifier, $token);

        if ($accepts !== null) {
            $this->components->error("The verification self-test failed: {$accepts}");

            return self::FAILURE;
        }

        $this->components->info('A payload signed with this token verifies, and a tampered one is refused.');

        return self::SUCCESS;
    }

    /**
     * Which bot this token actually belongs to.
     *
     * The username, not the display name, because it is what an operator
     * recognises from BotFather and from the `t.me` link they opened the app
     * with. If this is not the bot whose Mini App is failing, the token is the
     * whole problem and everything else in this report is noise.
     *
     * Only a token Telegram actively *rejects* is fatal. A host with no route to
     * api.telegram.org is a different problem from a host that cannot
     * authenticate anyone, and a diagnosis that dies on the first is useless
     * exactly when it is wanted.
     *
     * @return bool whether the caller should carry on
     */
    private function reportBotIdentity(Api $telegram): bool
    {
        try {
            $me = $telegram->getMe();
        } catch (TelegramResponseException $refused) {
            // Telegram answered and said no. Nothing further can work without a
            // usable token, so this is the end of the diagnosis — and it is the
            // one finding that explains a Mini App failing for everybody.
            $this->components->error("Telegram refused the bot token: {$refused->getMessage()}");

            return false;
        } catch (TelegramSDKException $unreachable) {
            $this->components->warn(
                "Could not reach Telegram ({$unreachable->getMessage()}), so the bot and its menu button were not checked."
            );

            return true;
        }

        $username = (string) $me->get('username');

        $this->components->twoColumnDetail(
            'Bot token belongs to',
            $username === '' ? '<fg=yellow>a bot with no username</>' : '@'.$username,
        );

        return true;
    }

    /**
     * What Telegram has actually *stored* as this bot's menu button.
     *
     * The question an operator is really asking when the app will not open is
     * "is the thing I configured actually there?", and until now the only way to
     * answer it was BotFather's UI — which shows what you typed, not what
     * Telegram kept. Reading it back is also the only way to catch the case
     * where the button points at some *other* URL, which opens fine and hands
     * the app an `initData` signed for a bot this server does not hold.
     */
    private function reportMenuButton(Api $telegram, string $miniAppUrl): void
    {
        try {
            $result = $telegram->post('getChatMenuButton')->getResult();
        } catch (TelegramSDKException $failed) {
            $this->components->warn("Could not read the menu button: {$failed->getMessage()}");

            return;
        }

        $button = is_array($result) ? $result : [];
        $type = (string) ($button['type'] ?? '');

        if ($type !== 'web_app') {
            // `default` is Telegram's built-in menu button and `commands` is the
            // command list. Neither opens a Mini App, so in both cases a user
            // has nothing in the chat to tap.
            $this->components->twoColumnDetail(
                'Menu button',
                $type === '' ? '<fg=yellow>not reported</>' : "<fg=yellow>{$type} — not a Mini App</>",
            );
            $this->components->warn(
                'No Mini App menu button is set, so there is nothing in the chat that opens the app. Run telegram:set-menu-button.'
            );

            return;
        }

        $webApp = is_array($button['web_app'] ?? null) ? $button['web_app'] : [];
        $url = (string) ($webApp['url'] ?? '');

        $this->components->twoColumnDetail(
            'Menu button',
            $url === '' ? '<fg=yellow>a Mini App with no URL</>' : $url,
        );

        if ($url !== '' && $miniAppUrl !== '' && $url !== $miniAppUrl) {
            $this->components->warn(
                "That is not the configured MINIAPP_URL ({$miniAppUrl}), so the app being opened is not the app described above."
            );
        }
    }

    /**
     * Whether the configured Mini App URL answers at all.
     *
     * The URL was checked for *syntax* and nothing else, which is how a 404 or a
     * 500 on the SPA stays invisible: its own failure is a blank page inside
     * Telegram, and this is the only vantage point outside that. A bad status is
     * a warning rather than a failure, because a host that cannot reach itself
     * is not evidence that Telegram cannot reach it.
     */
    private function reportReachability(string $miniAppUrl): void
    {
        if ($miniAppUrl === '') {
            return;
        }

        try {
            $status = Http::timeout(5)->connectTimeout(5)->get($miniAppUrl)->status();
        } catch (ConnectionException $unreachable) {
            $this->components->warn("MINIAPP_URL did not answer: {$unreachable->getMessage()}");

            return;
        }

        $this->components->twoColumnDetail(
            'MINIAPP_URL responds',
            $status >= 200 && $status < 300 ? (string) $status : "<fg=yellow>{$status}</>",
        );
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
     * The key comes from {@see InitDataSecretKey} rather than being derived here.
     * That is the whole point: this self-test signs with the key and then hands
     * the result to the real verifier, so if both derived it independently they
     * would agree with each other while both disagreeing with Telegram — which
     * is exactly how a self-test that always passed shipped alongside a server
     * that refused every real user. Sharing the one definition makes that
     * failure mode impossible; `InitDataGoldenVectorTest` independently pins the
     * definition itself.
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
            InitDataSecretKey::derive($token),
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

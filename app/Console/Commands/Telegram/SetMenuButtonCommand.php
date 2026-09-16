<?php

namespace App\Console\Commands\Telegram;

use Illuminate\Console\Command;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Puts the Mini App somewhere a user can actually find it.
 *
 * Registering a Web App with BotFather (`/newapp`) makes it reachable at
 * `t.me/<bot>/<app>` — a URL nobody types. It does **not** put anything in the
 * chat, so a user who has been told "open the Mini App" is looking at a
 * conversation with one button on it and no way to get there. The menu button is
 * the permanent tap target beside the message field, and this command is what
 * sets it.
 *
 * It reads `MINIAPP_URL` and refuses a URL Telegram would reject *before*
 * sending anything, because Telegram's rejection of a plain-HTTP Web App button
 * is a bare error for the operator and a silent dead button for every user.
 *
 * The read-back is not decoration. `setChatMenuButton` answering `true` says
 * Telegram accepted the call, not that the button now points where it was asked
 * to — and this is exactly the setting an operator has usually already changed
 * by hand in BotFather, where the UI shows what was typed rather than what was
 * stored. Setting and confirming in one command is what makes the difference
 * visible in one place.
 *
 * **Kept separate from `telegram:set-webhook` on purpose.** That command is the
 * one every deploy runs, and a Mini App URL that is unset must not fail webhook
 * registration; re-running `set-webhook` to change a Mini App URL would also be
 * a bad coupling of two unrelated registrations.
 */
class SetMenuButtonCommand extends Command
{
    /** @var string */
    protected $signature = 'telegram:set-menu-button';

    /** @var string */
    protected $description = "Point this bot's menu button at the Mini App, then read back what Telegram stored";

    public function handle(Api $telegram): int
    {
        $url = (string) config('services.telegram.miniapp_url');

        if ($url === '') {
            $this->components->error('Set MINIAPP_URL first — there is no app to point the button at.');

            return self::FAILURE;
        }

        // Telegram refuses a `web_app` button whose URL is not HTTPS, and it
        // refuses it at the API rather than in the client — so without this the
        // operator gets a raw API error and every user gets a menu button that
        // opens nothing. Say the real reason here instead.
        if (! str_starts_with($url, 'https://')) {
            $this->components->error(
                "Telegram only accepts an HTTPS Mini App URL, and MINIAPP_URL is {$url}."
            );

            return self::FAILURE;
        }

        try {
            $telegram->post('setChatMenuButton', ['menu_button' => $this->buttonFor($url)]);
        } catch (TelegramSDKException $refused) {
            $this->components->error("Telegram refused the menu button: {$refused->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info('Menu button registered.');

        return $this->confirmStored($telegram, $url);
    }

    /**
     * The `MenuButtonWebApp` object Telegram expects, JSON-encoded.
     *
     * `text` is **required**, and this command shipped without it believing the
     * opposite: Telegram refuses the entire call with `Bad Request: can't parse
     * menu button: Can't find field "text"`, so a Web App button with no label is
     * not "a button Telegram labels itself" — it is a button that cannot be
     * registered at all.
     *
     * The label is the app's own name, read from the **fallback** locale rather
     * than the viewer's or the operator's. `setChatMenuButton` takes a single
     * `text` for every user of the bot — there is no `language_code` on it, the
     * way there is on the command list — so a localised label is not something
     * this field can express. Reading the operator's locale instead would make
     * the label depend on whichever machine happened to run the command.
     *
     * Showing one word to both locales is not a missing translation here:
     * `common.app_name` is a brand, not a sentence, and a translated *sentence*
     * is what would be wrong in this slot.
     *
     * @throws \JsonException
     */
    private function buttonFor(string $url): string
    {
        return json_encode([
            'type' => 'web_app',
            'text' => $this->label(),
            'web_app' => ['url' => $url],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The button's label, from the fallback locale.
     *
     * A null locale is passed through as null rather than guessed at, which is
     * `trans()`'s own documented behaviour: it falls back to the current locale.
     */
    private function label(): string
    {
        $fallback = config('app.fallback_locale');

        return (string) trans('common.app_name', [], is_string($fallback) ? $fallback : null);
    }

    /**
     * Read the button back and compare it with what was asked for.
     *
     * A failure to read back is a warning rather than an error: the button was
     * set, and reporting a successful set as a failed command would send an
     * operator to fix something that is already right. A read-back that
     * *disagrees*, though, is a real failure — the whole point of running this
     * was to end up with a button pointing at that URL.
     */
    private function confirmStored(Api $telegram, string $url): int
    {
        try {
            $result = $telegram->post('getChatMenuButton')->getResult();
        } catch (TelegramSDKException $failed) {
            $this->components->warn(
                "The button was set, but Telegram would not read it back: {$failed->getMessage()}"
            );

            return self::SUCCESS;
        }

        $button = is_array($result) ? $result : [];
        $stored = $button['web_app']['url'] ?? null;

        $this->components->twoColumnDetail('Menu button', is_string($stored) ? $stored : '(none)');

        if ($stored !== $url) {
            $this->components->error(
                "Telegram stored a different button, so the Mini App being opened is not the one this server serves (expected {$url})."
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

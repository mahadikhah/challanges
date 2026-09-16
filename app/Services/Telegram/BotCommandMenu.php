<?php

namespace App\Services\Telegram;

use App\Providers\TelegramServiceProvider;
use App\Services\Localization;
use Illuminate\Support\Facades\Lang;

/**
 * The bot's command menu: what Telegram lists when a user opens the bot and taps
 * the menu button.
 *
 * Without it the bot registers no menu at all, so `/create`, `/checkin`, `/shop`
 * and `/language` are reachable only by someone who already knows they exist —
 * which nobody does on first use. The menu is the one surface where a command is
 * *discoverable*: nothing else the bot sends names one, save the `/start` line
 * that points at `/create` and `/cancel`.
 *
 * The list is written out here rather than derived from
 * {@see TelegramServiceProvider::BOT_COMMANDS}, so that adding a handler forces a
 * decision. `BotCommandMenuTest` pins the two against each other: a new command
 * word with no entry here fails the build, and the fix is either a description or
 * a written reason it is not something to offer on its own.
 *
 * Every handler earns a line today, `chatlink` included, and that is a decision
 * rather than an omission. It is creator-only and it takes an argument, so a bare
 * tap can never succeed — but Telegram scopes a command list per chat, never per
 * user, so "creator-only" is not expressible in a menu at all. The choice is
 * therefore between advertising it to everyone and hiding a shipped feature from
 * the only people who can use it; the former wins, because a non-creator who taps
 * it gets `bot.chatlink.no_challenge`, which names the shape of the command and
 * gives a worked example. A dead end that teaches beats silence.
 */
class BotCommandMenu
{
    /**
     * The commands a menu carries, each with the line that describes it.
     *
     * The descriptions live in `lang/{locale}/bot.php` with the rest of the bot's
     * copy, so they are translated like everything else it says. Telegram
     * truncates a long description in the list, which is why they are terse.
     *
     * This array's order is not the order a menu is rendered in — that is
     * {@see order()}'s business, and it is a rule rather than an array.
     *
     * @var array<string, string>
     */
    private const array MENU = [
        'start' => 'bot.commands.start',
        'create' => 'bot.commands.create',
        'checkin' => 'bot.commands.checkin',
        'chatlink' => 'bot.commands.chatlink',
        'shop' => 'bot.commands.shop',
        'language' => 'bot.commands.language',
        'cancel' => 'bot.commands.cancel',
    ];

    /** The command that opens the menu, wherever it would otherwise sort. */
    private const string FIRST = 'start';

    /** The command that closes it — the way out of whatever is open. */
    private const string LAST = 'cancel';

    public function __construct(private readonly Localization $localization) {}

    /**
     * The command words a menu carries, in the order Telegram will render them.
     *
     * The rule is stated rather than inherited from `MENU`'s array order: `start`
     * leads because it is always the way back, `cancel` trails because it is
     * always the way out, and everything else sorts alphabetically so that adding
     * a command never reopens the question of where it goes. Telegram renders a
     * command list in the order it was registered, so this order *is* the
     * affordance, not a detail of how it is built.
     *
     * @return list<string>
     */
    public function order(): array
    {
        $middle = array_values(array_diff(array_keys(self::MENU), [self::FIRST, self::LAST]));
        sort($middle);

        // Filtered against `MENU` rather than assumed present: a command dropped
        // from the list entirely would otherwise still be sent to Telegram, which
        // would happily register a word the bot does not answer to.
        return array_values(array_filter(
            [self::FIRST, ...$middle, self::LAST],
            static fn (string $command): bool => array_key_exists($command, self::MENU),
        ));
    }

    /**
     * The locales a menu is registered for, in the order it is registered in.
     *
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->localization->codes();
    }

    /**
     * One locale's menu, shaped for `setMyCommands`.
     *
     * @return list<array{command: string, description: string}>
     */
    public function forLocale(string $locale): array
    {
        return array_map(function (string $command) use ($locale): array {
            $description = Lang::get(self::MENU[$command], locale: $locale);

            return [
                'command' => $command,
                // A missing key resolves to the key itself, exactly as in
                // `BotMessenger::line()`. The menu then reads `bot.commands.shop`,
                // which is ugly and therefore visible — and an array here would
                // mean a whole group was asked for where a line belongs.
                'description' => is_string($description) ? $description : self::MENU[$command],
            ];
        }, $this->order());
    }

    /**
     * Every set the menu has to be registered as, in the order to send them.
     *
     * Telegram keeps the set sent *without* a `language_code` as the default — the
     * one a user gets when their client's language has no dedicated set — so it
     * goes first, and it is what stands between a speaker of a language this
     * platform does not serve and an empty menu. Each supported locale then gets
     * its own set, scoped by `language_code`.
     *
     * The fallback locale therefore appears twice, and that is deliberate rather
     * than wasteful: on Telegram's side the two are separate stores, and which one
     * a client reads depends on that client's own language, not on our fallback.
     *
     * @return list<array{language_code: string|null, commands: list<array{command: string, description: string}>}>
     */
    public function registrations(): array
    {
        /** @var list<array{language_code: string|null, commands: list<array{command: string, description: string}>}> $sets */
        $sets = [[
            'language_code' => null,
            'commands' => $this->forLocale($this->localization->fallback()),
        ]];

        foreach ($this->locales() as $locale) {
            $sets[] = ['language_code' => $locale, 'commands' => $this->forLocale($locale)];
        }

        return $sets;
    }
}

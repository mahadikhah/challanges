<?php

namespace App\Providers;

use App\Services\Telegram\CallbackRouter;
use App\Services\Telegram\Callbacks\CheckInCallback;
use App\Services\Telegram\Callbacks\JoinCallback;
use App\Services\Telegram\Callbacks\LanguageCallback;
use App\Services\Telegram\Callbacks\ReviewCheckInCallback;
use App\Services\Telegram\Callbacks\WizardCallback;
use App\Services\Telegram\CommandRouter;
use App\Services\Telegram\Commands\CancelCommand;
use App\Services\Telegram\Commands\CheckInCommand;
use App\Services\Telegram\Commands\CreateCommand;
use App\Services\Telegram\Commands\LanguageCommand;
use App\Services\Telegram\Commands\StartCommand;
use App\Services\Telegram\Handlers\CallbackQueryHandler;
use App\Services\Telegram\Handlers\MessageHandler;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\HandlesCallback;
use App\Services\Telegram\HandlesUpdate;
use App\Services\Telegram\LaravelHttpClient;
use App\Services\Telegram\UpdateRouter;
use App\Services\Telegram\Wizards\CreateChallengeWizard;
use Illuminate\Support\ServiceProvider;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Wires the bot SDK to exactly one bot, over exactly one transport.
 *
 * The SDK's own Laravel provider is disabled (`extra.laravel.dont-discover` in
 * composer.json) and this replaces it. Both reasons are about keeping the
 * transport fakeable, which CLAUDE.md requires of every Bot API call in tests:
 *
 * 1. The SDK takes its transport from `config('telegram.http_client_handler')`,
 *    and `BotsManager::makeBot()` passes that value straight into the `Api`
 *    constructor as an *instance*. Config files have to stay `var_export`-able for
 *    `config:cache`, so an object cannot live in one — the transport must be
 *    injected in code, which means owning the binding.
 * 2. `Telegram::sendMessage()` goes through `BotsManager::__call()`, which builds
 *    its own bot and never consults the container's `Api` binding. Leaving both
 *    wired would leave two ways to reach Telegram, only one of them fakeable: a
 *    test could pass while quietly calling the real API from the other.
 *
 * So there is one way in — resolve `Telegram\Bot\Api` — and the `Telegram` facade
 * is deliberately left unbound so that reaching for it fails loudly instead of
 * silently opening a second, unfaked path.
 *
 * The published `config/telegram.php` is left in place as the SDK's documentation
 * of its own options, but nothing reads it any more; platform code reads the
 * single `services.telegram.*` namespace.
 */
class TelegramServiceProvider extends ServiceProvider
{
    /**
     * Which handler acts on each kind of update, keyed by `TelegramUpdate::kind()`.
     *
     * The one place to read to learn everything the bot reacts to, and the one
     * place to edit to add a reaction. A kind listed in
     * `TelegramUpdate::HANDLED_KINDS` but absent here is recorded and logged
     * rather than acted on — the two lists are allowed to differ while a kind is
     * being adopted, because asking Telegram for a kind and knowing what to do
     * with it are separate deploys.
     *
     * @var array<string, class-string<HandlesUpdate>>
     */
    public const array UPDATE_HANDLERS = [
        'message' => MessageHandler::class,
        'callback_query' => CallbackQueryHandler::class,
    ];

    /**
     * Which handler serves each slash command, keyed by the command word.
     *
     * The same idea one level down: `UPDATE_HANDLERS` says what kinds of update
     * the bot reacts to, this says what a user can actually type. A command absent
     * from here gets the "I did not follow that" reply rather than silence.
     *
     * @var array<string, class-string<HandlesBotCommand>>
     */
    public const array BOT_COMMANDS = [
        'start' => StartCommand::class,
        'create' => CreateCommand::class,
        'checkin' => CheckInCommand::class,
        'language' => LanguageCommand::class,
        'cancel' => CancelCommand::class,
    ];

    /**
     * Which handler serves each inline button, keyed by its action word.
     *
     * `callback_data` is capped at 64 bytes, so an action word is short by
     * necessity — hence the mapping, which is also what lets a handler be renamed
     * without invalidating every button already sitting in a chat.
     *
     * @var array<string, class-string<HandlesCallback>>
     */
    public const array CALLBACK_HANDLERS = [
        CreateChallengeWizard::ACTION => WizardCallback::class,
        JoinCallback::ACTION => JoinCallback::class,
        CheckInCallback::ACTION => CheckInCallback::class,
        ReviewCheckInCallback::ACTION => ReviewCheckInCallback::class,
        LanguageCallback::ACTION => LanguageCallback::class,
    ];

    public function register(): void
    {
        $this->app->singleton(Api::class, function (): Api {
            $token = (string) config('services.telegram.bot_token');

            if ($token === '') {
                // Without a token the SDK would happily build `.../bot/sendMessage`
                // and report Telegram's 404 as the problem. Say the real one.
                throw new TelegramSDKException(
                    'TELEGRAM_BOT_TOKEN is not set, so no Bot API request can be made.'
                );
            }

            return new Api(
                token: $token,
                httpClientHandler: $this->app->make(LaravelHttpClient::class),
            );
        });

        $this->app->singleton(
            UpdateRouter::class,
            fn (): UpdateRouter => new UpdateRouter($this->app, self::UPDATE_HANDLERS),
        );

        $this->app->singleton(
            CommandRouter::class,
            fn (): CommandRouter => new CommandRouter($this->app, self::BOT_COMMANDS),
        );

        $this->app->singleton(
            CallbackRouter::class,
            fn (): CallbackRouter => new CallbackRouter($this->app, self::CALLBACK_HANDLERS),
        );
    }
}

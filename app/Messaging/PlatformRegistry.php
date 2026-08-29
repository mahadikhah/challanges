<?php

namespace App\Messaging;

use App\Actions\Observability\RecordExternalCall;
use App\Enums\MessagingPlatform;
use App\Messaging\Bale\BaleMessengerPlatform;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\Telegram\TelegramMessengerPlatform;
use Illuminate\Contracts\Container\Container;

/**
 * Maps a platform to its `MessengerPlatform` implementation.
 *
 * The interface's resolution discipline — "callers receive the platform
 * instance matching the actor: the webhook resolves it from the incoming
 * update, jobs from the recipient's stored `users.platform`" — needs exactly
 * this lookup to exist somewhere, and nowhere else. Consumers hold the
 * contract; this is the one place a case becomes a class.
 *
 * Implementations are resolved through the container so their own
 * constructor dependencies (SDK client, identity) are wired by the provider
 * rather than constructed ad hoc here.
 */
class PlatformRegistry
{
    /**
     * The implementation per platform case.
     *
     * @var array<string, class-string<MessengerPlatform>>
     */
    public const array IMPLEMENTATIONS = [
        MessagingPlatform::Telegram->value => TelegramMessengerPlatform::class,
        MessagingPlatform::Bale->value => BaleMessengerPlatform::class,
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * The platform implementation for one platform case.
     *
     * A `match` with no default arm is the whole point: a platform case added
     * to the enum without an implementation here is a fatal at resolution
     * time, not a silent fallthrough to some default bot that would answer
     * for the wrong messenger.
     */
    public function for(MessagingPlatform $platform): MessengerPlatform
    {
        $class = match ($platform) {
            MessagingPlatform::Telegram => TelegramMessengerPlatform::class,
            MessagingPlatform::Bale => BaleMessengerPlatform::class,
        };

        // Dressed in the counting skin: every platform resolved here counts
        // its outbound calls in the external-call stats, and no consumer can
        // bypass the counters without also bypassing this registry.
        return new RecordingMessengerPlatform(
            $this->container->make($class),
            $this->container->make(RecordExternalCall::class),
        );
    }
}

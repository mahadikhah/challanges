<?php

namespace App\Services\Telegram;

/**
 * initData that carried a valid signature, with the parts the platform needs
 * pulled out of their wire shapes.
 *
 * `user` is the decoded `User` object — the same shape a bot update's `from`
 * has, which is why `ResolveTelegramUser` accepts it unchanged. `fields` keeps
 * everything else as it arrived, which is where a deep link's `start_param`
 * will surface when the Mini App grows one.
 */
final readonly class VerifiedInitData
{
    /**
     * @param  array<string, mixed>  $user  Telegram's `User` object, decoded
     * @param  array<string, string>  $fields  every field except `hash` and
     *                                         `signature`, values as they arrived
     */
    public function __construct(
        public array $user,
        public array $fields,
    ) {}
}

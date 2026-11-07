<?php

namespace App\Services\Telegram\Commands;

use App\Actions\Telegram\VerifyChannelMembership;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\LinkChatFlow;

/**
 * `/chatlink` — open the link-a-chat flow for a challenge the caller created.
 *
 * The argument is the challenge's join token (`/chatlink j_abc123`), taken
 * from the same deep link a creator already has: the join link *is* the
 * challenge management handle, because it is unguessable without being a
 * secret the bot has to re-issue.
 *
 * The creator-only check is made twice, deliberately: once here so a
 * non-creator never gets the instruction, and once again in
 * `RegisterChallengeChat` when the forwarded message finally arrives — the
 * only check a flow can trust at the moment of the privileged act is the one
 * re-made at that moment.
 */
class ChatLinkCommand implements HandlesBotCommand
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly LinkChatFlow $flow,
        private readonly BotMessenger $messenger,
    ) {}

    public function handle(User $user, BotCommand $command): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $payload = $command->argument;

        if ($payload === null || ! Challenge::isJoinPayload($payload)) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.chatlink.no_challenge'));

            return;
        }

        $challenge = Challenge::fromJoinPayload($payload);

        if ($challenge === null) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.chatlink.challenge_gone'));

            return;
        }

        $this->flow->begin($user, $challenge);
    }
}

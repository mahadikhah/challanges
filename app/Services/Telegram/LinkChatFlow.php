<?php

namespace App\Services\Telegram;

use App\Actions\Challenges\RegisterChallengeChat;
use App\Actions\Challenges\VerifyChallengeChat;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\ChatLinkVerification;
use App\Enums\ConversationState;
use App\Enums\SettingKey;
use App\Enums\TelegramChatType;
use App\Exceptions\ChatLinkRefusedException;
use App\Exceptions\WrongChatTypeException;
use App\Models\BotConversation;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * The link-a-chat conversation: one instruction, one forwarded message, one
 * verdict.
 *
 * There is no reliable way for a bot to learn a chat id it has never seen, so
 * discovery is the user's move: they add the bot as admin, then forward any
 * message *from* that chat into the DM, and `forward_from_chat` — stamped by
 * Telegram on the sender's side, not writable by the forwarder — names the
 * chat. A chat id a user could type would be a chat id a user could point at
 * somebody else's channel.
 *
 * Everything about what happens next lives in the two actions: registration
 * (`RegisterChallengeChat` re-makes the ownership check) and verification
 * (`VerifyChallengeChat` makes both `getChatMember` checks and keeps or
 * withdraws `is_active`). This class owns only the sentences.
 *
 * Like every conversation flow, the privileged end — the verdict — re-checks
 * the channel gate rather than trusting the check that opened it.
 */
class LinkChatFlow
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly RegisterChallengeChat $register,
        private readonly VerifyChallengeChat $verify,
        private readonly BotMessenger $messenger,
        private readonly Settings $settings,
    ) {}

    /**
     * Open the flow for a challenge the caller resolved.
     *
     * The creator-only check is made by `RegisterChallengeChat` on
     * completion and re-made here before the first word, because ten minutes
     * of waiting for a forward from somebody who was never entitled to link
     * the chat is worse than an immediate refusal.
     */
    public function begin(User $user, Challenge $challenge): void
    {
        if ($challenge->creator_id !== $user->getKey()) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.chatlink.refused.not_the_creator'));

            return;
        }

        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        // One conversation per user: opening this replaces any wizard or
        // check-in flow half-built, exactly as `/checkin` does. A creator
        // reaching for "link a chat" is acting on a challenge that exists,
        // which is the thing that mattered.
        BotConversation::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            [
                'state' => ConversationState::AwaitingChatForward,
                'payload' => ['challenge_id' => $challenge->getKey()],
                'expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes)),
            ],
        );

        $this->messenger->paragraphs($user, [
            $this->messenger->line($user, 'bot.chatlink.prompt_title', ['title' => $challenge->title]),
            $this->messenger->line($user, 'bot.chatlink.prompt_add_bot'),
            $this->messenger->line($user, 'bot.chatlink.prompt_forward'),
            $this->messenger->line($user, 'bot.chatlink.prompt_cancel'),
        ]);
    }

    /**
     * The forwarded message, judged.
     *
     * Anything that is not a forwarded message from a chat — plain text, a
     * photo, a forward from a *user* — is re-asked with the same instruction,
     * because the instruction is one scroll up and the flow is one message
     * from finishing. `/cancel` reaches the command router before this does,
     * so it is not handled here.
     *
     * @param  array<string, mixed>  $message  the raw `message` object of the update
     */
    public function receiveForward(User $user, BotConversation $conversation, array $message): void
    {
        $challenge = $this->challengeOf($conversation);

        if ($challenge === null) {
            $conversation->delete();
            $this->messenger->send($user, $this->messenger->line($user, 'bot.chatlink.challenge_gone'));

            return;
        }

        // The one trusted source of a chat id: Telegram's own stamp on the
        // forward, present only when the original chat still exists and was
        // never a private conversation.
        $forwardFromChat = $message['forward_from_chat'] ?? null;

        if (! is_array($forwardFromChat) || ! isset($forwardFromChat['id'], $forwardFromChat['type'])) {
            $this->reask($user, $conversation, 'bot.chatlink.not_forwarded');

            return;
        }

        try {
            $chatType = TelegramChatType::fromForwardedChat($forwardFromChat['type']);
        } catch (WrongChatTypeException $unusable) {
            Log::info('A forwarded message named a chat this deploy cannot classify.', [
                'user_id' => $user->getKey(),
                'chat_type' => $unusable->chatType,
            ]);

            $this->reask($user, $conversation, 'bot.chatlink.wrong_type');

            return;
        }

        try {
            $chat = $this->register->handle(
                $user,
                $challenge,
                (int) $forwardFromChat['id'],
                $chatType,
                (string) ($forwardFromChat['title'] ?? '—'),
            );
        } catch (ChatLinkRefusedException $refused) {
            $conversation->delete();
            $this->messenger->send($user, $this->messenger->line($user, "bot.chatlink.refused.{$refused->reason->value}"));

            return;
        }

        // The verdict — and the privileged act — so the gate is asked again
        // here rather than trusted from when the flow opened.
        if (! $this->gate->ensure($user)) {
            $conversation->delete();
            $this->gatePrompt->send($user);

            return;
        }

        try {
            $outcome = $this->verify->handle($chat);
        } catch (TelegramSDKException $unobtainable) {
            // No verdict was obtained, so none was recorded and nothing was
            // sent. The conversation stays open: the queue's retry — or the
            // creator's second forward — lands back here, and the row is
            // still waiting to be verified.
            Log::error('A linked chat could not be verified: Telegram was unreachable.', [
                'user_id' => $user->getKey(),
                'challenge_chat_id' => $chat->getKey(),
                'reason' => $unobtainable->getMessage(),
            ]);

            $this->messenger->send($user, $this->messenger->line($user, 'bot.chatlink.unreachable'));

            return;
        }

        $conversation->delete();

        $this->messenger->send($user, $this->messenger->line($user, match ($outcome) {
            ChatLinkVerification::Verified => 'bot.chatlink.linked',
            ChatLinkVerification::BotNotAdmin => 'bot.chatlink.refused.bot_not_admin',
            ChatLinkVerification::CreatorNotAdmin => 'bot.chatlink.refused.creator_not_admin',
        }, [
            'title' => $chat->title,
            'challenge' => $challenge->title,
        ]));
    }

    /**
     * The challenge this conversation is linking to, or null when it has
     * vanished since the flow opened.
     */
    private function challengeOf(BotConversation $conversation): ?Challenge
    {
        $challengeId = $conversation->answer('challenge_id');

        if (! is_int($challengeId) && ! is_string($challengeId)) {
            return null;
        }

        return Challenge::query()->find($challengeId);
    }

    /**
     * Say the instruction again and keep waiting.
     */
    private function reask(User $user, BotConversation $conversation, string $line): void
    {
        $conversation->forceFill([
            'expires_at' => now()->addMinutes($this->settings->integer(SettingKey::ConversationTtlMinutes)),
        ])->save();

        $this->messenger->send($user, $this->messenger->line($user, $line));
    }
}

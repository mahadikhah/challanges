<?php

namespace App\Actions\Challenges;

use App\Enums\TelegramChatType;
use App\Exceptions\ChatLinkRefusedException;
use App\Models\Challenge;
use App\Models\ChallengeChat;
use App\Models\User;

/**
 * Record a chat a forwarded message discovered, against the challenge it
 * belongs to.
 *
 * The chat id arrives from exactly one place — `forward_from_chat` on a
 * message the creator forwarded from the chat (§2.6: there is no other
 * reliable way for a bot to learn a chat id it has not seen) — and this
 * action takes it as a parsed value rather than a payload, so no caller can
 * smuggle in a chat id typed by a user.
 *
 * **Ownership is re-made here, not trusted from the flow.** The wizard is one
 * surface; the posting jobs and any future admin path are others, and every
 * one of them has to hit the same "only the creator may bind a chat to their
 * challenge" check rather than each remembering to make it.
 *
 * The row lands unverified: recording a chat is a fact about a forwarded
 * message, while posting into it is a privilege `VerifyChallengeChat` grants
 * separately, and only after both admin checks pass.
 */
class RegisterChallengeChat
{
    /**
     * Record the chat, or revive the row that already names it.
     *
     * Re-linking the same chat to the same challenge reuses the row (the
     * unique pair is the arbiter), stripped back to unverified so the two
     * admin checks have to pass again — a second link attempt is exactly how
     * a creator recovers from having demoted the bot in between.
     *
     * The chat's platform is the creator's: the forward arrived in the
     * creator's DM on the messenger they use, so the chat it names is a chat
     * on that same messenger — and the row stores the platform so every later
     * post resolves the right bot.
     *
     * @throws ChatLinkRefusedException when the actor did not create the
     *                                  challenge
     */
    public function handle(
        User $creator,
        Challenge $challenge,
        int $telegramChatId,
        TelegramChatType $chatType,
        string $title,
    ): ChallengeChat {
        if ($challenge->creator_id !== $creator->getKey()) {
            throw ChatLinkRefusedException::notTheCreator();
        }

        return ChallengeChat::query()->updateOrCreate(
            [
                'challenge_id' => $challenge->getKey(),
                'platform' => $creator->platform,
                'telegram_chat_id' => $telegramChatId,
            ],
            [
                'chat_type' => $chatType,
                'title' => $title,
                'bot_admin_verified_at' => null,
                'creator_admin_verified_at' => null,
                'is_active' => false,
            ],
        );
    }
}

<?php

namespace App\Actions\Challenges;

use App\Enums\ChatLinkVerification;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\DTO\ChatMemberSnapshot;
use App\Messaging\PlatformRegistry;
use App\Models\ChallengeChat;
use App\Services\Telegram\BotMessenger;
use Illuminate\Support\Facades\Log;

/**
 * The two admin checks a linked chat has to pass — and keeps having to pass.
 *
 * A `ChallengeChat` row is a licence to post into somebody's channel, so the
 * licence is re-earned rather than granted once: `handle()` asks the chat's
 * own platform both questions and moves the row to whichever state the
 * answers justify. The wizard calls it at registration; the posting jobs
 * (§2.6) call `ensureFresh()` before every send, because admin status can be
 * revoked the day after registration and a row that still says "active"
 * would be a stale claim, not a fact.
 *
 * **A verdict of no is a state change, not an exception.** Deactivating and
 * telling the creator is the whole response; throwing would land the verdict
 * in the queue's failure handler, whose retry would re-ask the platform about a
 * chat we already know we lost — the retry-loop §2.6 rules out. A verdict we
 * could not *obtain* (platform unreachable, chat vanished) is different: that
 * propagates, so the job retries and asks again properly.
 */
class VerifyChallengeChat
{
    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly BotMessenger $messenger,
    ) {}

    /**
     * Ask both questions now and record the answers on the row.
     *
     * @throws MessengerException when the platform cannot be asked — no verdict
     *                            was obtained, so none is recorded
     */
    public function handle(ChallengeChat $chat): ChatLinkVerification
    {
        $challenge = $chat->challenge;
        $creator = $challenge->creator;

        $platform = $this->platforms->for($chat->platform);
        $botId = $platform->botId();

        if (! $this->botMayPost($platform, $chat, $botId)) {
            $chat->forceFill([
                'bot_admin_verified_at' => null,
                'is_active' => false,
            ])->save();

            return ChatLinkVerification::BotNotAdmin;
        }

        // An email-only creator can never satisfy an admin check on the
        // platform's side; read as a plain "no" rather than special-cased,
        // because the remedy (that person opens the bot) is the same.
        $creatorPlatformId = $creator->platform_user_id;
        $creatorIsAdmin = $creatorPlatformId !== null
            && $this->isAdminOf($platform, $chat->telegram_chat_id, $creatorPlatformId, requiresPostPrivilege: false);

        if (! $creatorIsAdmin) {
            $chat->forceFill([
                'creator_admin_verified_at' => null,
                'is_active' => false,
            ])->save();

            return ChatLinkVerification::CreatorNotAdmin;
        }

        $chat->forceFill([
            'bot_admin_verified_at' => now(),
            'creator_admin_verified_at' => now(),
            'is_active' => true,
        ])->save();

        return ChatLinkVerification::Verified;
    }

    /**
     * Re-verify a chat whose stamps have gone stale, or let a fresh one pass.
     *
     * This is the posting jobs' entry point: it spends no Bot API budget on a
     * chat verified minutes ago, deactivates quietly-failed chats, and tells
     * the creator once — the message rides here rather than in the job, so
     * every caller that re-verifies is a caller that notifies.
     *
     * @param  int  $ttlHours  how long a verification stays fresh
     *
     * @throws MessengerException when the platform cannot be asked
     */
    public function ensureFresh(ChallengeChat $chat, int $ttlHours): ChatLinkVerification
    {
        $verifiedAt = $chat->lastVerifiedAt();

        if ($chat->is_active && $verifiedAt !== null && $verifiedAt->gt(now()->subHours($ttlHours))) {
            return ChatLinkVerification::Verified;
        }

        $wasActive = $chat->is_active;

        $outcome = $this->handle($chat);

        if ($outcome !== ChatLinkVerification::Verified && $wasActive) {
            $this->notifyCreatorOfLoss($chat, $outcome);
        }

        return $outcome;
    }

    /**
     * Whether the bot is an admin of the chat, with posting rights where the
     * chat's kind demands them.
     *
     * @throws MessengerException
     */
    private function botMayPost(MessengerPlatform $platform, ChallengeChat $chat, int $botId): bool
    {
        $member = $this->getChatMember($platform, $chat->telegram_chat_id, $botId);

        if (! $member->isAdmin()) {
            return false;
        }

        // A channel admin can still be barred from posting — `getChatMember`
        // reports `can_post_messages` for channels only, which is why the
        // group branch asks nothing extra.
        return ! $chat->chat_type->requiresPostPrivilege() || $member->canPostMessages === true;
    }

    /**
     * Whether a human is an admin of the chat.
     *
     * @throws MessengerException
     */
    private function isAdminOf(MessengerPlatform $platform, int $chatId, int $platformUserId, bool $requiresPostPrivilege): bool
    {
        $member = $this->getChatMember($platform, $chatId, $platformUserId);

        if (! $member->isAdmin()) {
            return false;
        }

        return ! $requiresPostPrivilege || $member->canPostMessages === true;
    }

    /**
     * One `getChatMember`, kept here so both checks read identically.
     *
     * @throws MessengerException
     */
    private function getChatMember(MessengerPlatform $platform, int $chatId, int $userId): ChatMemberSnapshot
    {
        return $platform->getChatMember($chatId, $userId);
    }

    /**
     * Tell the creator their broadcast surface stopped working.
     *
     * The message names which check failed, because the two failures have
     * different fixes — re-add the bot, or re-admin yourself — and a creator
     * who cannot tell them apart cannot fix either. A creator the bot cannot
     * message (email-only) gets a log line instead of silence.
     */
    private function notifyCreatorOfLoss(ChallengeChat $chat, ChatLinkVerification $outcome): void
    {
        $creator = $chat->challenge->creator;

        if ($creator->platform_user_id === null) {
            Log::info('A linked chat failed re-verification against a creator the bot cannot message.', [
                'challenge_chat_id' => $chat->getKey(),
                'creator_id' => $creator->getKey(),
                'outcome' => $outcome->value,
            ]);

            return;
        }

        $this->messenger->send($creator, $this->messenger->line(
            $creator,
            "bot.chatlink.revoked.{$outcome->value}",
            ['title' => $chat->title, 'challenge' => $chat->challenge->title],
        ));
    }
}

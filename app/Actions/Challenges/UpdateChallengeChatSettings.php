<?php

namespace App\Actions\Challenges;

use App\Exceptions\ChatLinkRefusedException;
use App\Models\ChallengeChat;
use App\Models\User;

/**
 * Change what a linked chat broadcasts — and hold the privacy line.
 *
 * The three toggles are the creator's to set, but `share_proof_media` is not
 * theirs alone: a challenge whose proofs are private must not leak them
 * through a chat either (§2.6), and a guard that lived only in whatever UI
 * eventually flips the toggle would be a guard the next surface forgets to
 * copy. The check is made here, against the challenge as it stands *now* — a
 * challenge that was public-proofed at link time and switched private since
 * is refused on the next change, the same bargain the posting jobs make
 * before every send.
 */
class UpdateChallengeChatSettings
{
    /**
     * @param  array{share_proof_media?: bool, post_checkin_announcements?: bool, post_daily_leaderboard?: bool}  $settings
     *
     * @throws ChatLinkRefusedException when the actor did not create the
     *                                  challenge, or proof sharing was requested
     *                                  on a private-proof challenge
     */
    public function handle(User $creator, ChallengeChat $chat, array $settings): ChallengeChat
    {
        if ($chat->challenge->creator_id !== $creator->getKey()) {
            throw ChatLinkRefusedException::notTheCreator();
        }

        $wantsProofSharing = $settings['share_proof_media']
            ?? $chat->share_proof_media;

        if ($wantsProofSharing === true && ! $chat->challenge->sharesProofPublicly()) {
            throw ChatLinkRefusedException::privateProofs();
        }

        $chat->forceFill($settings)->save();

        return $chat;
    }
}

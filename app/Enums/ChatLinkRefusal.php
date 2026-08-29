<?php

namespace App\Enums;

/**
 * Why a chat link or chat setting was refused.
 *
 * The cases exist to be told apart — "you did not create that challenge" and
 * "that challenge's proofs are private" are different sentences — so the
 * caller selects the reply from `bot.chatlink.refused.{value}`.
 *
 * Deliberately **no** `label()`: the bot-facing copy lives in the lang files
 * keyed by the case, like `InviteRejection`.
 */
enum ChatLinkRefusal: string
{
    /**
     * The actor is not the challenge's creator.
     */
    case NotTheCreator = 'not_the_creator';

    /**
     * `share_proof_media` was requested on a challenge whose proofs are
     * private — the leaderboard side door §2.6 rules out.
     */
    case ProofsNotPublic = 'proofs_not_public';
}

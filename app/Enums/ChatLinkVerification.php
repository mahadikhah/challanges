<?php

namespace App\Enums;

/**
 * The outcome of the two admin checks a `ChallengeChat` has to pass.
 *
 * The cases exist to be told apart, not counted: "the bot is not an admin
 * there" and "*you* are not an admin there" are different sentences for a
 * creator to hear and different problems for them to fix, so the verdict
 * keeps the distinction rather than collapsing to a boolean.
 *
 * Deliberately **no** `label()`: the bot-facing sentence is chosen by the
 * caller, which knows whether it is talking to the creator mid-wizard or
 * reporting a chat that went quiet.
 */
enum ChatLinkVerification: string
{
    /**
     * The bot may post and the creator is an admin — the chat is active.
     */
    case Verified = 'verified';

    /**
     * The bot is not an admin of the chat, or (for a channel) cannot post.
     */
    case BotNotAdmin = 'bot_not_admin';

    /**
     * The registering creator is not an admin of the chat.
     */
    case CreatorNotAdmin = 'creator_not_admin';
}

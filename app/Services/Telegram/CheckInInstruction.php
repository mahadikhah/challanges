<?php

namespace App\Services\Telegram;

use App\Enums\FlowType;
use App\Enums\ProofType;
use App\Models\Challenge;
use App\Models\User;

/**
 * What a participant actually has to do to check in, as one sentence.
 *
 * The bot spent a long time telling people *that* to check in and never *how*:
 * a reminder said "check in now if you have not yet", the check-in listing said
 * "period 2 of 10 is open", and neither said whether that meant tapping a button,
 * typing a phrase or sending a photo. The answer differs per challenge, so it
 * cannot live in the message that carries it.
 *
 * **A collaborator rather than a method on `ProofType`, and the flow is why.**
 * A timed challenge is checked in through a session whatever its proof type is —
 * the session's final settlement still records evidence of that type, but what
 * the participant *does* is start a session and work through its steps. An enum
 * method on `ProofType` cannot see `Challenge::$flow_type` and would have to be
 * told, which is a collaborator with extra steps. The instruction is also bot
 * copy addressed to one recipient, so its rendering needs `BotMessenger`, not
 * just a key.
 *
 * **The `match` is exhaustive on purpose, with no `default`.** Adding a proof
 * type without deciding what its check-in looks like fails PHPStan rather than
 * quietly rendering a key that reads `bot.checkin.how.something_new` — the same
 * tripwire `BotCommandMenu` uses for a new command word.
 */
class CheckInInstruction
{
    public function __construct(private readonly BotMessenger $messenger) {}

    /**
     * The copy key for one challenge's instruction.
     */
    public function keyFor(Challenge $challenge): string
    {
        if ($challenge->flow_type === FlowType::TimedSession) {
            return 'bot.checkin.how.session';
        }

        return match ($challenge->proof_type) {
            ProofType::Button => 'bot.checkin.how.button',
            ProofType::TextAutogen => 'bot.checkin.how.text_autogen',
            ProofType::ImageApproval => 'bot.checkin.how.image_approval',
            ProofType::VoiceApproval => 'bot.checkin.how.voice_approval',
            ProofType::VideoApproval => 'bot.checkin.how.video_approval',
        };
    }

    /**
     * The instruction, in the recipient's own language.
     *
     * Read per recipient rather than per challenge: the same challenge is
     * explained to each participant in whichever language they chose, which is
     * the same rule every other line the bot says follows.
     */
    public function lineFor(User $user, Challenge $challenge): string
    {
        return $this->messenger->line($user, $this->keyFor($challenge));
    }
}

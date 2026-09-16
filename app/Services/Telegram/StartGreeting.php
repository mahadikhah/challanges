<?php

namespace App\Services\Telegram;

use App\Enums\InviteRejection;
use App\Models\Challenge;
use App\Models\Invite;
use App\Models\User;

/**
 * What `/start` says once the gate has let somebody in.
 *
 * Extracted from `StartCommand` because it now has two callers and they must
 * say the same thing: the command itself, and `LanguageCallback`, which owes
 * the greeting to anybody whose `/start` was interrupted by the language
 * question. The second caller is the reason the greeting is a class rather than
 * three private methods — it also needs `inviteNote()` on its own, to say a
 * word about the code they arrived with to a user who is still behind the gate.
 *
 * The three clauses stay one message. Telegram allows roughly a message a second
 * per chat, and a greeting plus an invite note plus a nudge sent separately is
 * how the third one is dropped with a 429.
 */
class StartGreeting
{
    public function __construct(
        private readonly BotMessenger $messenger,
        private readonly JoinChallengeFlow $joinFlow,
        private readonly BotButtons $buttons,
    ) {}

    /**
     * Deliver everything `/start` owed.
     *
     * A join payload short-circuits the greeting entirely: joining is the
     * conversation they meant to have, and a welcome in front of it would be
     * noise they have to scroll past. A payload that names nothing still gets
     * the join conversation's "no longer valid" — a dead link is not a reason to
     * pretend they arrived with nothing.
     */
    public function deliver(User $user, StartArrival $arrival): void
    {
        if ($arrival->joinPayload !== null) {
            $this->preview($user, $arrival->joinPayload);

            return;
        }

        $this->messenger->paragraphs($user, [
            $this->messenger->line($user, $arrival->isFirstArrival ? 'bot.start.welcome' : 'bot.start.welcome_back', [
                'name' => $this->greetingName($user),
                'app' => $this->messenger->line($user, 'common.app_name'),
            ]),
            $this->inviteNote($user, $arrival->invite),
            $this->messenger->line($user, 'bot.start.next_steps'),
        ], $this->nextSteps($user));
    }

    /**
     * The buttons on the closing nudge.
     *
     * `cancel` joins `create` only when there is actually something open to
     * cancel. A way out of a flow that is not running answers "there was nothing
     * to cancel", which is a worse reply than never having offered the button —
     * and `/start` is reachable at any time, so the two states are not
     * hypothetical.
     *
     * @return list<list<array{text: string, callback_data: string}>>|null
     */
    private function nextSteps(User $user): ?array
    {
        return $user->conversation()->live()->exists()
            ? $this->buttons->keyboard($user, 'create', 'cancel')
            : $this->buttons->keyboard($user, 'create');
    }

    /**
     * One line about the invite code they arrived with, or nothing if they didn't.
     *
     * Public because the gate prompt wants it too: a blocked user should hear
     * what became of their invite code whether or not they got in.
     */
    public function inviteNote(User $user, Invite|InviteRejection|null $outcome): ?string
    {
        if ($outcome === null) {
            return null;
        }

        if ($outcome instanceof InviteRejection) {
            // The rejection cases exist to be told apart: "that link is spent" and
            // "that is your own link" are different conversations.
            return $this->messenger->line($user, "bot.invite.refused.{$outcome->value}");
        }

        return $this->messenger->line(
            $user,
            $outcome->wasPaid() ? 'bot.invite.credited' : 'bot.invite.claimed',
            ['name' => $this->greetingName($outcome->inviter)],
        );
    }

    /**
     * Show what the deep link names, or say that it names nothing.
     */
    private function preview(User $user, string $joinPayload): void
    {
        $target = Challenge::fromJoinPayload($joinPayload);

        if ($target === null) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.join.not_found'));

            return;
        }

        $this->joinFlow->preview($user, $target);
    }

    /**
     * What to call somebody in a sentence.
     *
     * `first_name` is what Telegram users recognise as their own name; `name` is
     * the non-nullable column behind it, which for a bot user is the same thing
     * plus a surname and for a Fortify admin is a full name.
     */
    private function greetingName(User $user): string
    {
        return $user->first_name ?? $user->name;
    }
}

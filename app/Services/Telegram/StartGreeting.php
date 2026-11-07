<?php

namespace App\Services\Telegram;

use App\Enums\InviteRejection;
use App\Models\Challenge;
use App\Models\Invite;
use App\Models\User;
use App\Services\UserStats;

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
 * It is also the dashboard. `/start` is the one screen every user reaches and
 * the only one that has no reason not to show where they stand — how much they
 * have, what they are in, who else is here — and the four buttons under it are
 * how check-in became reachable without knowing that `/checkin` exists.
 *
 * The four clauses stay one message. Telegram allows roughly a message a second
 * per chat, and a greeting plus an invite note plus a set of figures plus a
 * nudge sent separately is how the last one is dropped with a 429.
 */
class StartGreeting
{
    public function __construct(
        private readonly BotMessenger $messenger,
        private readonly JoinChallengeFlow $joinFlow,
        private readonly BotButtons $buttons,
        private readonly UserStats $stats,
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
            $this->statsBlock($user),
            $this->messenger->line($user, 'bot.start.next_steps'),
        ], $this->nextSteps($user));
    }

    /**
     * Where the user stands, as one block.
     *
     * One paragraph with newlines inside it rather than five: `paragraphs()`
     * separates its elements with a blank line, and five short facts spread over
     * five paragraphs is a wall to scroll past. `bot.wizard.summary` is the
     * precedent.
     *
     * Every figure is shown to everybody, brand-new included. Zeros are honest,
     * they introduce the economy before it matters, and a greeting that
     * sometimes has a second half and sometimes does not is a greeting nobody
     * learns to read.
     */
    private function statsBlock(User $user): string
    {
        $stats = $this->stats->for($user);

        return implode("\n", [
            $this->messenger->line($user, 'bot.start.stats.joined', ['count' => $stats['joined']]),
            $this->messenger->line($user, 'bot.start.stats.created', ['count' => $stats['created']]),
            $this->messenger->line($user, 'bot.start.stats.coins', ['count' => $stats['coins']]),
            $this->messenger->line($user, 'bot.start.stats.people', ['count' => $stats['people']]),
            $this->messenger->line($user, 'bot.start.stats.platform', ['count' => $stats['platform']]),
        ]);
    }

    /**
     * The buttons that close the greeting.
     *
     * Two rows of two, actions above views. Four labels in one row wrap on a
     * phone, and of the four things here the first two are what the user came to
     * *do* — the two views they can come back to are the ones that can afford to
     * be second.
     *
     * No label is written here. They all come from `BotCommandMenu`, which is
     * what makes a button and its line in Telegram's command menu the same
     * string by construction rather than by discipline — and it is why check-in
     * is reachable at all now, for anybody who never guessed that `/checkin`
     * exists.
     *
     * `cancel` joins the second row only when there is actually something open
     * to cancel. A way out of a flow that is not running answers "there was
     * nothing to cancel", which is a worse reply than never having offered the
     * button — and `/start` is reachable at any time, so the two states are not
     * hypothetical.
     *
     * @return list<list<array{text: string, callback_data: string}>>
     */
    private function nextSteps(User $user): array
    {
        $rows = [
            $this->buttons->row($user, 'checkin', 'create'),
            $this->buttons->row($user, 'shop', 'challenges'),
        ];

        if ($user->conversation()->live()->exists()) {
            $rows[] = $this->buttons->row($user, 'cancel');
        }

        return $rows;
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

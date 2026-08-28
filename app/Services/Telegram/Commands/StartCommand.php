<?php

namespace App\Services\Telegram\Commands;

use App\Actions\Entitlements\GrantFreeBaseline;
use App\Actions\Invites\ClaimInvite;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\InviteRejection;
use App\Exceptions\InviteNotClaimableException;
use App\Models\Challenge;
use App\Models\Invite;
use App\Models\User;
use App\Services\Telegram\BotCommand;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\JoinChallengeFlow;
use Illuminate\Support\Facades\Log;

/**
 * `/start` — the front door, and the only command that works before the gate.
 *
 * Three pieces of Domain core, composed in an order that is load-bearing:
 *
 * **1. Attribution first, before the gate decides anything.** An invite pays its
 * inviter only when the invitee is brand-new, and "brand-new" is
 * `wasRecentlyCreated` on the instance this very update created. Block first and
 * the credit is not deferred — it is *lost*: the user joins the channel, sends
 * `/start` again, and by then their row already exists, so `ClaimInvite` finds
 * nobody new to pay. The framing that resolves it: the reward is for bringing a
 * person to the bot, which has already happened; the channel gate is about
 * *using* the bot, which has not.
 *
 * **2. The gate, always asking Telegram.** `handle()` rather than `ensure()`,
 * because the overwhelmingly likely reason somebody sends `/start` twice is that
 * they just tapped the join button — a cached "no" would send them round the loop
 * again.
 *
 * **3. Provisioning only once they are in.** `GrantFreeBaseline` runs after the
 * gate passes, so nothing is handed to a user who has not joined. It tops up
 * rather than grants, so running it on every `/start` is free and lifts existing
 * users when an admin raises the allowance.
 *
 * Everything the user is told goes out as **one** message. Telegram allows roughly
 * a message a second per chat, and a greeting plus an invite note plus a nudge
 * sent separately is how the third one gets dropped with a 429.
 */
class StartCommand implements HandlesBotCommand
{
    public function __construct(
        private readonly ClaimInvite $invites,
        private readonly VerifyChannelMembership $gate,
        private readonly GrantFreeBaseline $baseline,
        private readonly BotMessenger $messenger,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly JoinChallengeFlow $joinFlow,
    ) {}

    public function handle(User $user, BotCommand $command): void
    {
        // Read before anything writes to the row, so the greeting can tell a first
        // arrival from a returning user even if a later save clears the flag.
        $isFirstArrival = $user->wasRecentlyCreated;

        // A join payload is not an invite code, and the two must not be confused:
        // handing one to `ClaimInvite` would answer a dead challenge link with a
        // message about invite links. Join payloads are resolved here, invite
        // codes further down.
        $joinArrival = Challenge::isJoinPayload($command->argument);
        $joinTarget = $joinArrival ? Challenge::fromJoinPayload($command->argument) : null;

        $outcome = $joinArrival ? null : $this->attribute($user, $command->argument);

        if (! $this->gate->handle($user)) {
            $this->askToJoin($user, $outcome);

            return;
        }

        $this->baseline->handle($user);

        if ($joinArrival) {
            $joinTarget !== null
                ? $this->joinFlow->preview($user, $joinTarget)
                : $this->messenger->send($user, $this->messenger->line($user, 'bot.join.not_found'));

            return;
        }

        $this->welcome($user, $isFirstArrival, $outcome);
    }

    /**
     * Claim the `?start=` payload, if there was one.
     *
     * Returns the claimed invite, or the reason it was refused, or null when no
     * code was offered — three outcomes the reply has to distinguish.
     *
     * A refused code is **never** fatal to the arrival. The user still gets in;
     * they simply arrive unattributed, and are told which of the four things went
     * wrong rather than left wondering.
     */
    private function attribute(User $user, ?string $code): Invite|InviteRejection|null
    {
        if ($code === null) {
            return null;
        }

        try {
            return $this->invites->handle($user, $code);
        } catch (InviteNotClaimableException $refused) {
            Log::info('An invite code offered on /start was refused.', [
                'user_id' => $user->getKey(),
                'code' => $refused->inviteCode,
                'reason' => $refused->reason->value,
            ]);

            return $refused->reason;
        }
    }

    /**
     * Block with a join button until membership is confirmed.
     *
     * The refusal itself is `ChannelGatePrompt`'s, shared with every other
     * privileged command; the only thing `/start` adds is a word about the invite
     * code they arrived with, which they should hear whether or not they got in.
     */
    private function askToJoin(User $user, Invite|InviteRejection|null $outcome): void
    {
        $this->gatePrompt->send($user, [$this->inviteNote($user, $outcome)]);
    }

    /**
     * Let them in.
     */
    private function welcome(User $user, bool $isFirstArrival, Invite|InviteRejection|null $outcome): void
    {
        $this->messenger->paragraphs($user, [
            $this->messenger->line($user, $isFirstArrival ? 'bot.start.welcome' : 'bot.start.welcome_back', [
                'name' => $this->greetingName($user),
                'app' => $this->messenger->line($user, 'common.app_name'),
            ]),
            $this->inviteNote($user, $outcome),
            $this->messenger->line($user, 'bot.start.next_steps'),
        ]);
    }

    /**
     * One line about the invite code they arrived with, or nothing if they didn't.
     */
    private function inviteNote(User $user, Invite|InviteRejection|null $outcome): ?string
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

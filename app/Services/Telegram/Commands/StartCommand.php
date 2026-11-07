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
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesBotCommand;
use App\Services\Telegram\LanguagePrompt;
use App\Services\Telegram\StartArrival;
use App\Services\Telegram\StartGreeting;
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
 * **4. The language question, once, before the greeting.** A user whose `locale`
 * is still null has never chosen one — the column is written only by
 * `LanguageCallback` and by the Mini App — so `/start` asks instead of greeting,
 * and everything this arrival owed travels on the buttons (`StartArrival`) to
 * be delivered the moment they answer. It sits *after* the gate because a
 * blocked user is owed the gate prompt and nothing else, and because the
 * question they answer here has to outlive the loop they are about to go round:
 * `locale` is still null on the `/start` that finally gets them through, which
 * is exactly why the trigger is the column and not `wasRecentlyCreated`.
 *
 * Everything the user is told goes out as **one** message. Telegram allows roughly
 * a message a second per chat, and a greeting plus an invite note plus a dashboard
 * plus a nudge sent separately is how the last one gets dropped with a 429.
 */
class StartCommand implements HandlesBotCommand
{
    public function __construct(
        private readonly ClaimInvite $invites,
        private readonly VerifyChannelMembership $gate,
        private readonly GrantFreeBaseline $baseline,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly StartGreeting $greeting,
        private readonly LanguagePrompt $languagePrompt,
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
        $joinPayload = Challenge::isJoinPayload($command->argument) ? trim((string) $command->argument) : null;

        $outcome = $joinPayload !== null ? null : $this->attribute($user, $command->argument);

        $arrival = new StartArrival($isFirstArrival, $outcome, $joinPayload);

        if (! $this->gate->handle($user)) {
            $this->gatePrompt->send($user, [$this->greeting->inviteNote($user, $outcome)]);

            return;
        }

        $this->baseline->handle($user);

        if ($user->locale === null) {
            // Asked in the fallback locale, which is the only honest one to ask
            // it in: there is no answer to render it in yet.
            $this->languagePrompt->send($user, $arrival->carry());

            return;
        }

        $this->greeting->deliver($user, $arrival);
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
}

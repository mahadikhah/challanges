<?php

namespace App\Actions\Invites;

use App\Enums\CoinTransactionReason;
use App\Enums\InviteStatus;
use App\Enums\SettingKey;
use App\Exceptions\InviteNotClaimableException;
use App\Models\Invite;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Attribute an arrival to an invite, and pay the inviter if the arrival was new.
 *
 * The single rule the product hangs on: **an invite credits coins only if the
 * invited user is brand-new to the bot.** Otherwise the whole economy is a
 * treadmill — anyone with two Telegram accounts, or a friend who already uses the
 * bot, could mint coins by passing a code around.
 *
 * `Claimed` and `Credited` are both success. An existing user redeeming a code is
 * still attributed — the inviter can see they brought them, and `referred_by_user_id`
 * is set — they are simply not paid for it.
 *
 * **Where the safety actually comes from.** Not from this method being careful,
 * but from four independent things, each of which holds on its own:
 *
 * 1. `invites.code` is unique and the row is locked here, so two arrivals racing
 *    on one code are serialised and the loser is told it is taken.
 * 2. `invites.invited_user_id` is unique, so a person can be attributed to at most
 *    one inviter for their whole life — no second bite even with a fresh code.
 * 3. The ledger key is `invite:<id>`, so one invite row pays exactly once however
 *    many times `/start` is retried.
 * 4. Eligibility is read off server state, never off an argument.
 */
class ClaimInvite
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CoinLedger $ledger,
    ) {}

    /**
     * Claim `$code` for `$invitee`.
     *
     * **`$invitee` must be the instance the arrival itself created or found** —
     * the model that `firstOrCreate` returned on this `/start`, not one re-loaded
     * afterwards. That instance is what knows whether the row is new, and that is
     * what decides whether the inviter gets paid. See `isBrandNew()`.
     *
     * Returns the claimed invite; read its `status` to find out whether coins
     * moved.
     *
     * @throws InviteNotClaimableException when the code is unknown, is the
     *                                     invitee's own, is already spent, or the
     *                                     invitee already arrived through another
     */
    public function handle(User $invitee, string $code): Invite
    {
        $code = self::normalise($code);

        return DB::transaction(function () use ($invitee, $code): Invite {
            $invite = $this->lockCode($code);

            if ($invite === null) {
                throw InviteNotClaimableException::notFound($code);
            }

            // Already this invitee's: Telegram retried the update, or they tapped
            // the link twice. Returning the row is the whole of idempotency here —
            // every write below has already happened.
            if ($invite->invited_user_id === $invitee->getKey()) {
                return $invite;
            }

            if ($invite->inviter_id === $invitee->getKey()) {
                throw InviteNotClaimableException::selfInvite($invite);
            }

            if ($invite->isClaimed()) {
                throw InviteNotClaimableException::alreadyClaimed($invite);
            }

            if ($this->alreadyAttributed($invitee)) {
                throw InviteNotClaimableException::inviteeAlreadyAttributed($invite);
            }

            $paid = $this->payInviter($invite, $invitee);

            $invite->update([
                'invited_user_id' => $invitee->getKey(),
                'status' => $paid ? InviteStatus::Credited : InviteStatus::Claimed,
                'credited_at' => $paid ? now() : null,
            ]);

            $invitee->update(['referred_by_user_id' => $invite->inviter_id]);

            return $invite;
        });
    }

    /**
     * The comparable form of a code.
     *
     * Codes are generated lowercase, so folding case here is what makes a link
     * survive a phone keyboard capitalising the first letter. Public because the
     * bot echoes the code back and should echo the form that was matched.
     */
    public static function normalise(string $code): string
    {
        return Str::lower(trim($code));
    }

    /**
     * Take the code's row `FOR UPDATE`, so concurrent arrivals queue.
     *
     * The lock is on the invite rather than on either user, because the contended
     * resource is the code: two brand-new users tapping the same link at once must
     * not both be attributed to it. Locking here and letting `CoinLedger` take the
     * inviter's lock inside `credit()` also fixes the order as invite → user for
     * every caller, so these can never deadlock against each other.
     */
    private function lockCode(string $code): ?Invite
    {
        return Invite::query()->where('code', $code)->lockForUpdate()->first();
    }

    /**
     * Whether this user has already been attributed to somebody.
     *
     * Checked against the database rather than the in-memory `referred_by_user_id`,
     * which may be a stale null on a model loaded before another claim committed.
     * The unique index on `invited_user_id` is the real backstop; this exists so
     * the caller gets a domain exception it can explain instead of a constraint
     * violation it cannot.
     */
    private function alreadyAttributed(User $invitee): bool
    {
        return $invitee->claimedInvite()->exists();
    }

    /**
     * Pay the inviter if this arrival earns it. Returns whether coins moved.
     *
     * The reward comes from `Setting` at claim time, so an admin changing the rate
     * changes it for the next arrival and not retroactively.
     *
     * A zero or negative reward pays nothing and settles the invite as `Claimed`
     * rather than `Credited`, because `Credited` means the inviter was paid and
     * `wasPaid()` has to keep meaning that. An admin who zeroes the rate has turned
     * invite rewards off, not made them free.
     */
    private function payInviter(Invite $invite, User $invitee): bool
    {
        if (! $this->isBrandNew($invitee)) {
            return false;
        }

        $reward = $this->settings->integer(SettingKey::InviteCoinReward);

        if ($reward < 1) {
            return false;
        }

        $this->ledger->credit(
            $invite->inviter,
            $reward,
            CoinTransactionReason::InviteCredit,
            "invite:{$invite->getKey()}",
            $invite,
        );

        return true;
    }

    /**
     * Whether this arrival is a first-ever `/start` rather than a returning user.
     *
     * `wasRecentlyCreated` is true only on the instance that performed the INSERT
     * in this process, which is exactly the question being asked and is not
     * something a client can influence — unlike a `bool $isNew` argument, which
     * would put "should I be paid?" in the hands of the caller on a money path.
     *
     * It also **fails closed**: a re-loaded model reports false and the inviter
     * goes unpaid. Under-paying an invite is a support conversation; over-paying
     * one is a mint.
     */
    private function isBrandNew(User $invitee): bool
    {
        return $invitee->wasRecentlyCreated;
    }
}

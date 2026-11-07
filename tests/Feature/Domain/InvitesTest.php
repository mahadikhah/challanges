<?php

use App\Actions\Invites\ClaimInvite;
use App\Actions\Invites\IssueInviteCode;
use App\Enums\CoinTransactionReason;
use App\Enums\InviteRejection;
use App\Enums\InviteStatus;
use App\Enums\MessagingPlatform;
use App\Enums\SettingKey;
use App\Exceptions\InviteNotClaimableException;
use App\Models\Invite;
use App\Models\User;
use App\Services\CoinLedger;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->settings = app(Settings::class);
    $this->ledger = app(CoinLedger::class);
    $this->issue = app(IssueInviteCode::class);
    $this->claim = app(ClaimInvite::class);

    $this->inviter = User::factory()->telegram()->create();
});

/**
 * A user as they arrive on a *later* `/start` — an existing account.
 *
 * `wasRecentlyCreated` is what `ClaimInvite` reads to decide whether the inviter
 * is paid, and a factory-built model has it set because the factory just inserted
 * the row. Re-loading the model is how a test says "this person was already here",
 * and it is exactly what the bot does for a returning user: a find, not a create.
 */
function returning(User $user): User
{
    return User::query()->findOrFail($user->getKey());
}

describe('minting a code', function () {
    it('gives the inviter something to share', function () {
        config()->set('services.telegram.bot_username', 'challengebot');

        $invite = $this->issue->handle($this->inviter);

        expect($invite->inviter_id)->toBe($this->inviter->id)
            ->and($invite->status)->toBe(InviteStatus::Pending)
            ->and($invite->invited_user_id)->toBeNull()
            ->and($invite->deepLink(MessagingPlatform::Telegram))->toBe("https://t.me/challengebot?start={$invite->code}");
    });

    it('hands back the same link when asked again', function () {
        // The link a user copied yesterday has to still be the one they see today,
        // otherwise every visit to the invite screen orphans the code they shared.
        $first = $this->issue->handle($this->inviter);
        $second = $this->issue->handle($this->inviter);

        expect($second->id)->toBe($first->id)
            ->and($this->inviter->sentInvites()->count())->toBe(1);
    });

    it('mints a fresh one once the last was claimed', function () {
        $claimed = $this->issue->handle($this->inviter);
        $this->claim->handle(User::factory()->telegram()->create(), $claimed->code);

        $next = $this->issue->handle($this->inviter);

        expect($next->id)->not->toBe($claimed->id)
            ->and($next->status)->toBe(InviteStatus::Pending);
    });

    it('mints a second code on demand while one is still open', function () {
        // For an inviter who has posted their link publicly and wants a separate
        // one for a specific friend.
        $open = $this->issue->handle($this->inviter);
        $extra = $this->issue->mint($this->inviter);

        expect($extra->id)->not->toBe($open->id)
            ->and($extra->code)->not->toBe($open->code)
            ->and($this->inviter->sentInvites()->open()->count())->toBe(2);
    });

    it('keeps handing back the oldest open code once several exist', function () {
        $first = $this->issue->mint($this->inviter);
        $this->issue->mint($this->inviter);

        expect($this->issue->handle($this->inviter)->id)->toBe($first->id);
    });

    it('generates codes that survive being read aloud and retyped', function () {
        // No i, l, o, 0 or 1: every ambiguous character is a support message.
        $codes = collect(range(1, 40))->map(fn (): string => $this->issue->mint($this->inviter)->code);

        foreach ($codes as $code) {
            expect($code)->toHaveLength(10)
                ->and($code)->toMatch('/^[a-hj-km-np-z2-9]+$/');
        }
    });

    it('does not repeat itself', function () {
        $codes = collect(range(1, 60))->map(fn (): string => $this->issue->mint($this->inviter)->code);

        expect($codes->unique())->toHaveCount(60);
    });

    it('gives different inviters different codes', function () {
        $other = User::factory()->telegram()->create();

        expect($this->issue->handle($this->inviter)->code)
            ->not->toBe($this->issue->handle($other)->code);
    });
});

describe('claiming an invite as a brand-new user', function () {
    it('pays the inviter', function () {
        $invite = $this->issue->handle($this->inviter);
        $invitee = User::factory()->telegram()->create();

        $claimed = $this->claim->handle($invitee, $invite->code);

        expect($claimed->status)->toBe(InviteStatus::Credited)
            ->and($claimed->wasPaid())->toBeTrue()
            ->and($claimed->credited_at)->not->toBeNull()
            ->and($this->ledger->balanceFor($this->inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward));
    });

    it('attributes the arrival both ways round', function () {
        $invite = $this->issue->handle($this->inviter);
        $invitee = User::factory()->telegram()->create();

        $this->claim->handle($invitee, $invite->code);

        expect($invitee->refresh()->referred_by_user_id)->toBe($this->inviter->id)
            ->and($invitee->referrer->id)->toBe($this->inviter->id)
            ->and($invitee->claimedInvite->id)->toBe($invite->id)
            ->and($this->inviter->referrals()->pluck('id')->all())->toBe([$invitee->id]);
    });

    it('writes one explained ledger entry', function () {
        $invite = $this->issue->handle($this->inviter);

        $this->claim->handle(User::factory()->telegram()->create(), $invite->code);

        $entry = $this->inviter->coinTransactions()->sole();

        expect($entry->reason)->toBe(CoinTransactionReason::InviteCredit)
            ->and($entry->reference)->toBeInstanceOf(Invite::class)
            ->and($entry->reference->id)->toBe($invite->id);
    });

    it('pays whatever the admin has set the rate to', function () {
        // The rate is a Setting, so there is no coin figure to find in the code.
        $this->settings->set(SettingKey::InviteCoinReward, 37);
        $invite = $this->issue->handle($this->inviter);

        $this->claim->handle(User::factory()->telegram()->create(), $invite->code);

        expect($this->ledger->balanceFor($this->inviter))->toBe(37);
    });

    it('records the arrival unpaid when the reward has been turned off', function (int $reward) {
        // Zero coins is not a payment, so the invite must not claim it was one —
        // `wasPaid()` has to keep meaning what it says.
        $this->settings->set(SettingKey::InviteCoinReward, $reward);
        $invite = $this->issue->handle($this->inviter);
        $invitee = User::factory()->telegram()->create();

        $claimed = $this->claim->handle($invitee, $invite->code);

        expect($claimed->status)->toBe(InviteStatus::Claimed)
            ->and($claimed->credited_at)->toBeNull()
            ->and($this->ledger->balanceFor($this->inviter))->toBe(0)
            ->and($invitee->refresh()->referred_by_user_id)->toBe($this->inviter->id);
    })->with([
        'switched off' => 0,
        'misconfigured negative' => -5,
    ]);

    it('accepts the code however the keyboard mangled it', function (string $offered) {
        $invite = Invite::factory()->withCode('abcde23456')->create(['inviter_id' => $this->inviter->id]);

        $claimed = $this->claim->handle(User::factory()->telegram()->create(), $offered);

        expect($claimed->id)->toBe($invite->id)
            ->and($claimed->status)->toBe(InviteStatus::Credited);
    })->with([
        'shouted' => 'ABCDE23456',
        'capitalised' => 'Abcde23456',
        'padded' => "  abcde23456\n",
    ]);
});

describe('claiming an invite as someone who was already here', function () {
    it('attributes the arrival but pays nothing', function () {
        // The rule the economy hangs on. Two accounts, or a friend who already uses
        // the bot, must not be a coin printer.
        $invite = $this->issue->handle($this->inviter);
        $invitee = returning(User::factory()->telegram()->create());

        $claimed = $this->claim->handle($invitee, $invite->code);

        expect($claimed->status)->toBe(InviteStatus::Claimed)
            ->and($claimed->wasPaid())->toBeFalse()
            ->and($claimed->credited_at)->toBeNull()
            ->and($this->ledger->balanceFor($this->inviter))->toBe(0);
    });

    it('still records who brought them', function () {
        // Unpaid is not unattributed: the inviter can still see they brought this
        // person, and the row still explains why the code is spent.
        $invite = $this->issue->handle($this->inviter);
        $invitee = returning(User::factory()->telegram()->create());

        $claimed = $this->claim->handle($invitee, $invite->code);

        expect($claimed->invited_user_id)->toBe($invitee->id)
            ->and($claimed->isClaimed())->toBeTrue()
            ->and($invitee->refresh()->referred_by_user_id)->toBe($this->inviter->id);
    });

    it('spends the code, so it cannot be retried against a new account', function () {
        $invite = $this->issue->handle($this->inviter);
        $this->claim->handle(returning(User::factory()->telegram()->create()), $invite->code);

        expect(fn () => $this->claim->handle(User::factory()->telegram()->create(), $invite->code))
            ->toThrow(InviteNotClaimableException::class);

        expect($this->ledger->balanceFor($this->inviter))->toBe(0);
    });
});

describe('refusing a claim', function () {
    it('refuses a code that does not exist', function () {
        $failure = null;

        try {
            $this->claim->handle(User::factory()->telegram()->create(), 'NoSuchCode');
        } catch (InviteNotClaimableException $e) {
            $failure = $e;
        }

        expect($failure?->reason)->toBe(InviteRejection::NotFound)
            ->and($failure?->invite)->toBeNull()
            ->and($failure?->inviteCode)->toBe('nosuchcode');
    });

    it('refuses the inviter their own code', function () {
        $invite = $this->issue->handle($this->inviter);

        expect(fn () => $this->claim->handle($this->inviter, $invite->code))
            ->toThrow(function (InviteNotClaimableException $e) use ($invite) {
                expect($e->reason)->toBe(InviteRejection::SelfInvite)
                    ->and($e->invite?->id)->toBe($invite->id);
            });

        expect($this->ledger->balanceFor($this->inviter))->toBe(0)
            ->and($invite->refresh()->status)->toBe(InviteStatus::Pending);
    });

    it('refuses a code somebody else already arrived through', function () {
        $invite = $this->issue->handle($this->inviter);
        $first = User::factory()->telegram()->create();
        $this->claim->handle($first, $invite->code);

        expect(fn () => $this->claim->handle(User::factory()->telegram()->create(), $invite->code))
            ->toThrow(function (InviteNotClaimableException $e) {
                expect($e->reason)->toBe(InviteRejection::AlreadyClaimed);
            });

        expect($invite->refresh()->invited_user_id)->toBe($first->id);
    });

    it('pays the inviter once when two arrivals race for one code', function () {
        // The invite row is locked for the claim, so the second arrival finds it
        // taken rather than both being attributed to it.
        $invite = $this->issue->handle($this->inviter);
        $winner = User::factory()->telegram()->create();
        $loser = User::factory()->telegram()->create();

        $this->claim->handle($winner, $invite->code);

        expect(fn () => $this->claim->handle($loser, $invite->code))
            ->toThrow(InviteNotClaimableException::class);

        expect($this->ledger->balanceFor($this->inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward))
            ->and($loser->refresh()->referred_by_user_id)->toBeNull();
    });

    it('refuses a second invite to someone who already came in through one', function () {
        // Attribution is for life. Otherwise a popular user could be re-credited to
        // a new inviter every week.
        $invitee = User::factory()->telegram()->create();
        $this->claim->handle($invitee, $this->issue->handle($this->inviter)->code);

        $second = User::factory()->telegram()->create();
        $their = $this->issue->handle($second);

        expect(fn () => $this->claim->handle($invitee, $their->code))
            ->toThrow(function (InviteNotClaimableException $e) {
                expect($e->reason)->toBe(InviteRejection::InviteeAlreadyAttributed);
            });

        expect($this->ledger->balanceFor($second))->toBe(0)
            ->and($their->refresh()->status)->toBe(InviteStatus::Pending)
            ->and($invitee->refresh()->referred_by_user_id)->toBe($this->inviter->id);
    });

    it('leaves nothing behind when it refuses', function () {
        // The whole claim is one transaction, so a refusal cannot half-attribute.
        $invite = $this->issue->handle($this->inviter);

        expect(fn () => $this->claim->handle($this->inviter, $invite->code))
            ->toThrow(InviteNotClaimableException::class);

        expect($invite->refresh()->invited_user_id)->toBeNull()
            ->and($invite->credited_at)->toBeNull()
            ->and($this->inviter->refresh()->referred_by_user_id)->toBeNull()
            ->and($this->inviter->coinTransactions()->count())->toBe(0);
    });
});

describe('a retried /start', function () {
    it('returns the same invite and pays once', function () {
        // Telegram retries any update it does not get a 2xx for, and a user can tap
        // a deep link twice.
        $invite = $this->issue->handle($this->inviter);
        $invitee = User::factory()->telegram()->create();

        $first = $this->claim->handle($invitee, $invite->code);
        $second = $this->claim->handle($invitee, $invite->code);
        $third = $this->claim->handle($invitee->refresh(), $invite->code);

        expect($second->id)->toBe($first->id)
            ->and($third->id)->toBe($first->id)
            ->and($this->inviter->coinTransactions()->count())->toBe(1)
            ->and($this->ledger->balanceFor($this->inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward));
    });

    it('pays per invite row, not per claim', function () {
        // The ledger key is `invite:<id>`, so even a row reset by hand cannot be
        // used to pay the same invite a second time. This is the last line of
        // defence behind the status checks above, so it is worth proving directly
        // rather than trusting them. Written through the query builder because the
        // point is a row that changed underneath the model.
        $invite = $this->issue->handle($this->inviter);
        $this->claim->handle(User::factory()->telegram()->create(), $invite->code);

        Invite::query()->whereKey($invite->getKey())->update([
            'invited_user_id' => null,
            'status' => InviteStatus::Pending->value,
            'credited_at' => null,
        ]);
        $this->claim->handle(User::factory()->telegram()->create(), $invite->code);

        expect($this->inviter->coinTransactions()->count())->toBe(1)
            ->and($this->ledger->balanceFor($this->inviter))
            ->toBe($this->settings->integer(SettingKey::InviteCoinReward));
    });

    it('does not double-count a returning user either', function () {
        $invite = $this->issue->handle($this->inviter);
        $invitee = returning(User::factory()->telegram()->create());

        $this->claim->handle($invitee, $invite->code);
        $again = $this->claim->handle($invitee, $invite->code);

        expect($again->status)->toBe(InviteStatus::Claimed)
            ->and($this->ledger->balanceFor($this->inviter))->toBe(0);
    });
});

describe('the invite ledger over time', function () {
    it('pays an inviter for each separate friend they bring', function () {
        $reward = $this->settings->integer(SettingKey::InviteCoinReward);

        foreach (range(1, 3) as $ignored) {
            $this->claim->handle(
                User::factory()->telegram()->create(),
                $this->issue->handle($this->inviter)->code,
            );
        }

        expect($this->ledger->balanceFor($this->inviter))->toBe($reward * 3)
            ->and($this->inviter->sentInvites()->credited()->count())->toBe(3)
            ->and($this->inviter->referrals()->count())->toBe(3);
    });

    it('keeps the rate that applied at the time of each claim', function () {
        // Changing the rate is not retroactive: the ledger records what was
        // actually paid, and re-reading a Setting must never restate history.
        $this->settings->set(SettingKey::InviteCoinReward, 10);
        $this->claim->handle(User::factory()->telegram()->create(), $this->issue->handle($this->inviter)->code);

        $this->settings->set(SettingKey::InviteCoinReward, 25);
        $this->claim->handle(User::factory()->telegram()->create(), $this->issue->handle($this->inviter)->code);

        expect($this->ledger->balanceFor($this->inviter))->toBe(35)
            ->and($this->inviter->coinTransactions()->pluck('amount')->all())->toBe([25, 10]);
    });

    it('reconciles against its own ledger', function () {
        $this->claim->handle(User::factory()->telegram()->create(), $this->issue->handle($this->inviter)->code);

        expect($this->ledger->drift($this->inviter))->toBe(0);
    });
});

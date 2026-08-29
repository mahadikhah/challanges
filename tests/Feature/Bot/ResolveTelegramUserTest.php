<?php

use App\Actions\Telegram\ResolveTelegramUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The bot has no sign-up step, so this action *is* registration. Two things about
 * it are load-bearing and neither is obvious from reading it:
 *
 * 1. The instance it returns is what decides whether an inviter gets paid, because
 *    `ClaimInvite` reads `wasRecentlyCreated` off it. A second lookup anywhere
 *    downstream reports false and the credit is silently lost.
 * 2. `locale` is written once and never again. It is what the user chose; their
 *    Telegram client language must not be able to overwrite that on their next
 *    message.
 */

/**
 * Resolve a user from a Telegram `from` object, filled in around the id.
 *
 * @param  array<string, mixed>  $from
 */
function arrivalFrom(array $from): User
{
    return app(ResolveTelegramUser::class)->handle(array_replace([
        'id' => 777_000_1,
        'is_bot' => false,
        'first_name' => 'Sara',
    ], $from));
}

describe('a first arrival', function () {
    it('creates the user from what Telegram said about them', function () {
        $user = arrivalFrom([
            'first_name' => 'Sara',
            'last_name' => 'Karimi',
            'username' => 'sara_k',
            'language_code' => 'fa-IR',
        ]);

        expect($user->platform_user_id)->toBe(777_000_1)
            ->and($user->first_name)->toBe('Sara')
            ->and($user->name)->toBe('Sara Karimi')
            ->and($user->telegram_username)->toBe('sara_k')
            ->and($user->language_code)->toBe('fa-IR')
            // `fa-IR` is not a locale this platform serves; `fa` is. Reducing the
            // tag is `Localization::best()`'s job, and this is where it happens.
            ->and($user->locale)->toBe('fa');
    });

    it('reports itself as recently created, which is what pays an inviter', function () {
        // The single most consequential assertion in this file: `ClaimInvite` reads
        // exactly this flag to decide whether an invite credits coins.
        expect(arrivalFrom([])->wasRecentlyCreated)->toBeTrue();
    });

    it('is a Telegram identity with nothing to sign in with', function () {
        $user = arrivalFrom([]);

        // The two auth paths must stay disjoint: bot users are Telegram-only,
        // admins are email plus password through Fortify.
        expect($user->isTelegramUser())->toBeTrue()
            ->and($user->email)->toBeNull()
            ->and($user->password)->toBeNull();
    });

    it('arrives unverified and unprivileged', function () {
        $user = arrivalFrom([]);

        // Neither column is fillable, and nothing here writes them — so both come
        // back as the column default. The gate grants the first; an admin grants the
        // second. Read from the database, because an attribute this action never
        // touched is simply absent from the in-memory instance.
        expect($user->channel_verified_at)->toBeNull()
            ->and($user->hasVerifiedChannel())->toBeFalse()
            ->and($user->fresh()?->is_admin)->toBeFalse();
    });

    it('falls back to a username, then to the id, for the non-nullable name', function (array $from, string $expected) {
        expect(arrivalFrom($from)->name)->toBe($expected);
    })->with([
        'first name only' => [['first_name' => 'Sara', 'last_name' => null], 'Sara'],
        'first and last' => [['first_name' => 'Sara', 'last_name' => 'Karimi'], 'Sara Karimi'],
        'username only' => [['first_name' => null, 'username' => 'sara_k'], 'sara_k'],
        'blank first name' => [['first_name' => '   ', 'username' => 'sara_k'], 'sara_k'],
        'nothing at all' => [['first_name' => null], 'Telegram 7770001'],
    ]);

    it('refuses a payload with no usable id', function (mixed $id) {
        expect(fn () => app(ResolveTelegramUser::class)->handle(['id' => $id, 'first_name' => 'Sara']))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'missing' => [null],
        'a string' => ['7770001'],
        'zero' => [0],
        'negative' => [-1],
    ]);
});

describe('a returning user', function () {
    it('finds the existing row rather than creating a second', function () {
        $first = arrivalFrom([]);
        $second = arrivalFrom([]);

        expect($second->getKey())->toBe($first->getKey())
            ->and($second->wasRecentlyCreated)->toBeFalse()
            ->and(User::query()->count())->toBe(1);
    });

    it('picks up a changed username and display name', function () {
        arrivalFrom(['username' => 'sara_k', 'first_name' => 'Sara']);

        $user = arrivalFrom(['username' => 'sara_karimi', 'first_name' => 'Sara', 'last_name' => 'Karimi']);

        // Usernames change, and admin search goes stale otherwise.
        expect($user->telegram_username)->toBe('sara_karimi')
            ->and($user->name)->toBe('Sara Karimi');
    });

    it('never overwrites a chosen locale with a Telegram client language', function () {
        $user = arrivalFrom(['language_code' => 'en']);

        // The user then picks Farsi in the bot or the Mini App.
        $user->update(['locale' => 'fa']);

        $returning = arrivalFrom(['language_code' => 'en']);

        // Their client still reports English. Their choice wins, and the raw tag is
        // still recorded for reference.
        expect($returning->locale)->toBe('fa')
            ->and($returning->language_code)->toBe('en');
    });

    it('does not write when Telegram told us nothing new', function () {
        $user = arrivalFrom(['username' => 'sara_k']);

        // Backdate without touching timestamps, so a save on the way through is
        // visible as a bumped `updated_at` rather than invisible.
        User::query()->whereKey($user->getKey())->update(['updated_at' => now()->subDay()]);

        arrivalFrom(['username' => 'sara_k']);

        expect($user->refresh()->updated_at?->isYesterday())->toBeTrue();
    });

    it('leaves a verified membership and an admin flag alone', function () {
        $user = arrivalFrom([]);
        $user->forceFill(['channel_verified_at' => now()->subHour(), 'is_admin' => true])->save();

        $returning = arrivalFrom(['username' => 'changed_since']);

        expect($returning->hasVerifiedChannel())->toBeTrue()
            ->and($returning->is_admin)->toBeTrue();
    });
});

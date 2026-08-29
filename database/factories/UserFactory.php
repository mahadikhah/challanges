<?php

namespace Database\Factories;

use App\Enums\MessagingPlatform;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['test-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * A bot user: Telegram identity and nothing to sign in with.
     *
     * The credential columns are explicitly nulled rather than left at the
     * factory default, because a bot user that happens to carry a password hash
     * would let a test pass that should not — the two auth paths must stay
     * disjoint.
     */
    public function telegram(?int $telegramId = null): static
    {
        return $this->state(function (array $attributes) use ($telegramId): array {
            $firstName = fake()->firstName();

            return [
                'platform' => MessagingPlatform::Telegram,
                'platform_user_id' => $telegramId ?? fake()->unique()->numberBetween(100_000_000, 9_999_999_999),
                'telegram_username' => fake()->unique()->userName(),
                'name' => $firstName,
                'first_name' => $firstName,
                'language_code' => fake()->randomElement(['en', 'fa']),
                'email' => null,
                'email_verified_at' => null,
                'password' => null,
                'remember_token' => null,
            ];
        });
    }

    /**
     * A bot user on Bale: same shape as `telegram()`, different messenger.
     *
     * Composes after `telegram()` in tests (`User::factory()->telegram()->bale()`)
     * rather than repeating the credential-nulling this state has nothing to do
     * with — platform_user_id stays whatever `telegram()` drew, because the two
     * messengers' id spaces are independent and a collision is a legitimate
     * test fixture, not a bug.
     */
    public function bale(): static
    {
        return $this->state(fn (array $attributes) => [
            'platform' => MessagingPlatform::Bale,
        ]);
    }

    /**
     * Grant the admin flag. Not fillable on the model, so this is the only
     * convenient way to get one — which is the point.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_admin' => true,
        ]);
    }

    /**
     * Announcement-channel membership already confirmed.
     */
    public function channelVerified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'channel_verified_at' => now(),
        ]);
    }

    /**
     * Pin the locale this user has chosen.
     */
    public function preferring(string $locale): static
    {
        return $this->state(fn (array $attributes): array => [
            'locale' => $locale,
        ]);
    }

    /**
     * Attribute this user to the invite that brought them in.
     */
    public function referredBy(User $referrer): static
    {
        return $this->state(fn (array $attributes): array => [
            'referred_by_user_id' => $referrer->id,
        ]);
    }
}

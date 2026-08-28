<?php

namespace App\Actions\Telegram;

use App\Enums\MessagingPlatform;
use App\Models\User;
use App\Services\Localization;
use InvalidArgumentException;

/**
 * Turn Telegram's `User` object into the platform's own user row.
 *
 * The platform has no sign-up step: a user exists because they messaged the
 * bot or opened the Mini App. Those are the two arrivals this action serves —
 * a bot update's `from` and initData's decoded `user` have the same shape —
 * and it remains the only place a Telegram identity becomes a `User`.
 *
 * **The returned instance matters, not just the row.** `ClaimInvite` decides
 * whether an inviter gets paid from `wasRecentlyCreated`, which is true only on
 * the instance that performed the INSERT in this process. So a caller must pass
 * *this* instance onward rather than re-loading the user afterwards, or every
 * invite silently goes unpaid. `MessageHandler` resolves once per update for
 * exactly that reason.
 *
 * **What this never touches.** `is_admin` and `channel_verified_at` are privilege
 * state and are not fillable on the model; nothing here writes them. `locale` is
 * written **only at creation**: it is what the user chose in the bot or the Mini
 * App, and their Telegram client language must not be allowed to overwrite a
 * deliberate choice on their next message.
 */
class ResolveTelegramUser
{
    public function __construct(private readonly Localization $localization) {}

    /**
     * Find or create the user behind a Telegram `User` object.
     *
     * Telegram's own profile fields — first name, username, client language — are
     * refreshed on the way through, because usernames change and admin search
     * goes stale otherwise. The write only happens when something actually
     * differs, so an ordinary message costs one SELECT.
     *
     * @param  array<string, mixed>  $from  Telegram's `User` object, as it arrived
     *
     * @throws InvalidArgumentException when the payload carries no usable `id`
     */
    public function handle(array $from): User
    {
        $telegramId = $from['id'] ?? null;

        if (! is_int($telegramId) || $telegramId < 1) {
            // Every Telegram `User` object has an `id`. Its absence means the
            // payload is not what it claims to be, and inventing a user for it
            // would attach real state to a fiction.
            throw new InvalidArgumentException('A Telegram user payload arrived without a usable id.');
        }

        $profile = $this->profile($from, $telegramId);

        $user = User::query()->firstOrCreate(
            ['platform' => MessagingPlatform::Telegram, 'platform_user_id' => $telegramId],
            [...$profile, 'locale' => $this->localization->best($profile['language_code'])],
        );

        if (! $user->wasRecentlyCreated) {
            $user->fill($profile);

            if ($user->isDirty()) {
                $user->save();
            }
        }

        return $user;
    }

    /**
     * The columns Telegram owns, shaped for both insert and refresh.
     *
     * @param  array<string, mixed>  $from
     * @return array{first_name: string|null, name: string, telegram_username: string|null, language_code: string|null}
     */
    private function profile(array $from, int $telegramId): array
    {
        $firstName = $this->text($from, 'first_name');
        $username = $this->text($from, 'username');

        return [
            'first_name' => $firstName,
            'name' => $this->displayName($firstName, $this->text($from, 'last_name'), $username, $telegramId),
            'telegram_username' => $username,
            'language_code' => $this->text($from, 'language_code'),
        ];
    }

    /**
     * Something to put in the non-nullable `name` column.
     *
     * The Bot API always sends `first_name`, so the fallbacks are belt and braces
     * for a malformed payload — but `name` cannot be null, and failing an arrival
     * over a missing display name would be a poor trade.
     */
    private function displayName(?string $first, ?string $last, ?string $username, int $telegramId): string
    {
        $full = trim(implode(' ', array_filter([$first, $last])));

        return match (true) {
            $full !== '' => $full,
            $username !== null => $username,
            default => "Telegram {$telegramId}",
        };
    }

    /**
     * A trimmed string field, or null when it is absent, blank, or not a string.
     *
     * @param  array<string, mixed>  $from
     */
    private function text(array $from, string $key): ?string
    {
        $value = $from[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

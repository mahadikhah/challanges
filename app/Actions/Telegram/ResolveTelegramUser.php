<?php

namespace App\Actions\Telegram;

use App\Enums\MessagingPlatform;
use App\Models\User;
use App\Services\Localization;
use InvalidArgumentException;

/**
 * Turn a messenger's `User` object into the platform's own user row.
 *
 * The platform has no sign-up step: a user exists because they messaged the
 * bot or opened the Mini App. Those are the arrivals this action serves — a
 * bot update's `from` and initData's decoded `user` have the same shape — and
 * it remains the only place a messenger identity becomes a `User`.
 *
 * Bale and Telegram send the same `User` object shape (verified against
 * docs.bale.ai), so one resolver serves both; which messenger the identity
 * belongs to is the caller's fact to name, because only the caller knows
 * where the payload came from — the webhook knows its own route, the Mini App
 * auth path is Telegram's `initData`.
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
 * App, and their client language must not be allowed to overwrite a deliberate
 * choice on their next message.
 */
class ResolveTelegramUser
{
    public function __construct(private readonly Localization $localization) {}

    /**
     * Find or create the user behind a messenger `User` object.
     *
     * The profile fields — first name, username, client language — are
     * refreshed on the way through, because usernames change and admin search
     * goes stale otherwise. The write only happens when something actually
     * differs, so an ordinary message costs one SELECT.
     *
     * @param  array<string, mixed>  $from  the platform's `User` object, as it arrived
     * @param  MessagingPlatform  $platform  which messenger issued that object
     *
     * @throws InvalidArgumentException when the payload carries no usable `id`
     */
    public function handle(array $from, MessagingPlatform $platform = MessagingPlatform::Telegram): User
    {
        $platformUserId = $from['id'] ?? null;

        if (! is_int($platformUserId) || $platformUserId < 1) {
            // Every messenger `User` object has an `id`. Its absence means the
            // payload is not what it claims to be, and inventing a user for it
            // would attach real state to a fiction.
            throw new InvalidArgumentException("A {$platform->value} user payload arrived without a usable id.");
        }

        $profile = $this->profile($from, $platformUserId, $platform);

        $user = User::query()->firstOrCreate(
            ['platform' => $platform, 'platform_user_id' => $platformUserId],
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
     * The columns the messenger owns, shaped for both insert and refresh.
     *
     * @param  array<string, mixed>  $from
     * @return array{first_name: string|null, name: string, telegram_username: string|null, language_code: string|null}
     */
    private function profile(array $from, int $platformUserId, MessagingPlatform $platform): array
    {
        $firstName = $this->text($from, 'first_name');
        $username = $this->text($from, 'username');

        return [
            'first_name' => $firstName,
            'name' => $this->displayName($firstName, $this->text($from, 'last_name'), $username, $platformUserId, $platform),
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
    private function displayName(?string $first, ?string $last, ?string $username, int $platformUserId, MessagingPlatform $platform): string
    {
        $full = trim(implode(' ', array_filter([$first, $last])));

        return match (true) {
            $full !== '' => $full,
            $username !== null => $username,
            default => ucfirst($platform->value).' '.$platformUserId,
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

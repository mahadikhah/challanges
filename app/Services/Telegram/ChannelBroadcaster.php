<?php

namespace App\Services\Telegram;

use App\Actions\Telegram\VerifyChannelMembership;
use App\Models\Challenge;
use App\Services\Localization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;
use Throwable;

/**
 * Talks to the announcement channel rather than to one user.
 *
 * The sibling of `BotMessenger`, and separate from it for one reason: every
 * decision that class makes is about a recipient — their chat id, their locale —
 * and a channel has neither. A channel has a mixed-language audience, so posts go
 * out in the platform's fallback locale, and its chat id comes from the
 * `required_channel` setting rather than from a `users` row.
 */
class ChannelBroadcaster
{
    public function __construct(
        private readonly Api $telegram,
        private readonly VerifyChannelMembership $gate,
        private readonly Localization $localization,
    ) {}

    /**
     * Post a public challenge, exactly once.
     *
     * Claims the challenge with `announced_at` in a single conditional UPDATE, so
     * two workers racing the same row cannot both post; the loser sees zero
     * affected rows and returns. On a failed post the claim is released and the
     * error rethrown, which leaves the queue free to retry and
     * `Challenge::awaitsAnnouncement()` true rather than a challenge silently
     * marked announced that nobody ever saw.
     *
     * @return bool whether this call is the one that posted
     *
     * @throws TelegramSDKException when Telegram refuses the post
     */
    public function announce(Challenge $challenge): bool
    {
        if (! $challenge->visibility->shouldAnnounce()) {
            // An invite-only challenge is not a failure to announce; there is
            // nothing to announce. Reachable when a challenge changed visibility
            // between dispatch and execution.
            return false;
        }

        $claimedAt = now();

        $claimed = Challenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('announced_at')
            ->update(['announced_at' => $claimedAt, 'updated_at' => $claimedAt]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $this->telegram->sendMessage([
                'chat_id' => $this->gate->channel(),
                'text' => $this->post($challenge),
            ]);
        } catch (Throwable $failure) {
            // Release, so the retry is not silently swallowed by our own claim.
            // Guarded by `announced_at` still holding *our* timestamp: if another
            // process has since claimed and posted, releasing would invite a
            // duplicate.
            DB::table('challenges')
                ->where('id', $challenge->getKey())
                ->where('announced_at', $claimedAt)
                ->update(['announced_at' => null]);

            Log::warning('A public challenge could not be announced.', [
                'challenge_id' => $challenge->getKey(),
                'reason' => $failure->getMessage(),
            ]);

            throw $failure;
        }

        // The claim was a query-builder UPDATE, which the instance we were handed
        // knows nothing about. Sync only this attribute, so the caller reads the
        // stamp it just made — and does not read the instance as still holding an
        // unsaved change — without discarding anything else pending on the model.
        $challenge->forceFill(['announced_at' => $claimedAt])->syncOriginalAttribute('announced_at');

        return true;
    }

    /**
     * What the channel sees.
     *
     * No join button yet: a public challenge is joined through the bot, and that
     * flow lands with the join task. A button that goes nowhere is worse than a
     * post that says where to go.
     */
    private function post(Challenge $challenge): string
    {
        $locale = $this->localization->fallback();

        $lines = [
            $this->line('bot.announce.headline', ['title' => $challenge->title], $locale),
            $challenge->description,
            $this->line('bot.announce.details', [
                'period' => $this->line($challenge->period_type->translationKey(), [], $locale),
                'periods' => $challenge->total_periods,
                'proof' => $this->line($challenge->proof_type->translationKey(), [], $locale),
            ], $locale),
            $this->line('bot.announce.how_to_join', [], $locale),
        ];

        return implode("\n\n", array_filter(
            $lines,
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        ));
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    private function line(string $key, array $replace, string $locale): string
    {
        $line = Lang::get($key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }
}

<?php

namespace App\Services\Telegram;

use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\MessagingPlatform;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\PlatformRegistry;
use App\Models\Challenge;
use App\Services\Localization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to the announcement channel rather than to one user.
 *
 * The sibling of `BotMessenger`, and separate from it for one reason: every
 * decision that class makes is about a recipient — their chat id, their locale —
 * and a channel has neither. A channel has a mixed-language audience, so posts go
 * out in the platform's fallback locale, and its chat id comes from the
 * platform's `required_channel` setting rather than from a `users` row.
 *
 * Which channel a challenge announces into follows its creator: a challenge
 * made on Bale is announced where Bale users can see it, and the same for
 * Telegram. A creator with no messenger identity (a web-only admin) defaults
 * to Telegram, which is the announcement surface that existed before there
 * were two.
 */
class ChannelBroadcaster
{
    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly VerifyChannelMembership $gate,
        private readonly Localization $localization,
        private readonly PeriodUnit $units,
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
     * @throws MessengerException when the platform refuses the post
     */
    public function announce(Challenge $challenge): bool
    {
        if (! $challenge->visibility->shouldAnnounce()) {
            // An invite-only challenge is not a failure to announce; there is
            // nothing to announce. Reachable when a challenge changed visibility
            // between dispatch and execution.
            return false;
        }

        // The creator's row names the platform the announcement belongs on. A
        // creator with no messenger identity (a web-only admin) falls back to
        // Telegram — the announcement surface that existed before there were
        // two — rather than failing a challenge that is otherwise announceable.
        //
        // Resolved *before* the claim, deliberately: an unconfigured channel
        // throws here, and if the claim had already been taken the challenge
        // would be stuck marked announced with nothing posted.
        $creator = $challenge->creator;
        $platformCase = $creator->platform ?? MessagingPlatform::Telegram;
        $channel = $this->gate->channel($creator);
        $platform = $this->platforms->for($platformCase);

        $claimedAt = now();

        $claimed = Challenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('announced_at')
            ->update(['announced_at' => $claimedAt, 'updated_at' => $claimedAt]);

        if ($claimed === 0) {
            return false;
        }

        try {
            $platform->sendMessage(
                $channel,
                $this->post($challenge),

                // The button is a URL deep link, deliberately, and not
                // `callback_data`. A bot cannot open a conversation with a user
                // who has never messaged it — a `callback_query` reply would 403
                // for exactly the audience a channel post exists to reach — so
                // the link has to carry them into the bot first. `?start=` then
                // delivers the join token to `/start`, which knows what to do
                // with it.
                [[[
                    'text' => $this->line('bot.announce.join_button', [], $this->localization->fallback()),
                    'url' => $challenge->joinLink($platformCase),
                ]]],
            );
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
     * The join button is a `url` deep link rather than a `callback_data` one, and
     * that is not a styling choice: a bot cannot write to somebody who has never
     * opened a private chat with it, and the audience a channel post addresses is
     * precisely people who have not. `callback_data` here would leave a button
     * that 403s for exactly its intended tappers. The link lands on `/start` with
     * the challenge's join token in the payload.
     */
    private function post(Challenge $challenge): string
    {
        $locale = $this->localization->fallback();

        $lines = [
            $this->line('bot.announce.headline', ['title' => $challenge->title], $locale),
            $challenge->description,
            $this->line('bot.announce.details', [
                'period' => $this->line($challenge->period_type->translationKey(), [], $locale),
                // No recipient exists to ask, so the unit resolves in the same
                // fallback locale the rest of this post is written in.
                'length' => $this->units->length(null, $challenge),
                'proof' => $this->line($challenge->proof_type->translationKey(), [], $locale),
            ], $locale),
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

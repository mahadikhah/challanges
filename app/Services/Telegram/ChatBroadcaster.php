<?php

namespace App\Services\Telegram;

use App\Actions\Challenges\VerifyChallengeChat;
use App\Enums\ChatLinkVerification;
use App\Enums\SettingKey;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Messaging\DTO\SentMessage;
use App\Messaging\PlatformRegistry;
use App\Models\ChallengeChat;
use App\Services\Localization;
use App\Services\Settings;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;

/**
 * Talks to a linked chat rather than to one user.
 *
 * The second sibling of `BotMessenger` (the announcement channel has
 * `ChannelBroadcaster`, a challenge's home chats have this). The same reasons
 * apply: a linked chat has a mixed-language audience, so posts go out in the
 * platform's fallback locale, and its id comes from the `ChallengeChat` row
 * rather than from a `users` row — a row that only exists because two admin
 * checks passed.
 *
 * **Idempotency is the caller's key, not this class's.** `claim()` takes a
 * `ChallengeChatPost` row in a conditional insert and says whether this call
 * is the one that may send; a losing caller returns without saying anything,
 * which is how a re-dispatched job is a no-op rather than a duplicate in
 * front of an audience.
 */
class ChatBroadcaster
{
    public function __construct(
        private readonly PlatformRegistry $platforms,
        private readonly VerifyChallengeChat $verifier,
        private readonly Localization $localization,
        private readonly Settings $settings,
    ) {}

    /**
     * Claim the right to send one post, if it is still unclaimed.
     *
     * A single INSERT — not a read-then-write — so two copies of the same job
     * racing on different workers cannot both claim: MySQL serialises them on
     * the unique index, the loser gets the constraint violation, and the row
     * means "posted" from the moment it exists. The caller deletes the row it
     * claimed if its send fails, releasing the key to the queue's retry.
     *
     * @param  array{challenge_period_id?: int, challenge_participant_id?: int, post_date?: string}  $key
     */
    public function claim(ChallengeChat $chat, string $kind, array $key): bool
    {
        try {
            $chat->posts()->create(['post_kind' => $kind, ...$key]);

            return true;
        } catch (UniqueConstraintViolationException) {
            // Already posted by a duplicate of this job — the exact outcome
            // the table exists to produce.
            return false;
        }
    }

    /**
     * Send text into the chat, re-verifying the chat first if its stamps went
     * stale.
     *
     * @param  list<string|null>  $lines  nulls dropped, blank-line separated
     * @return bool whether the message went out
     */
    public function sendLines(ChallengeChat $chat, array $lines): bool
    {
        return $this->deliver(
            $chat,
            fn (): SentMessage => $this->platformFor($chat)->sendMessage(
                $chat->telegram_chat_id,
                $this->text($lines),
            ),
        );
    }

    /**
     * Send a photo (with a caption) into the chat, falling back to text.
     *
     * The caption is the announcement itself; the photo is the proof that was
     * approved. If the stored file has vanished from disk the text still goes
     * out — a missing photo should not swallow the news, and the queue has
     * nothing to gain from retrying a 404 against our own storage.
     *
     * @param  list<string|null>  $lines  the caption, same shape as `sendLines`
     */
    public function sendPhoto(ChallengeChat $chat, string $path, array $lines): bool
    {
        $bytes = Storage::disk('local')->get($path);

        if ($bytes === null) {
            return $this->sendLines($chat, $lines);
        }

        return $this->deliver($chat, fn (): SentMessage => $this->platformFor($chat)->sendPhoto(
            $chat->telegram_chat_id,
            // From contents rather than a path: the proof bytes are on our
            // disk, not at a URL the platform can fetch.
            $bytes,
            'proof.jpg',
            [$this->text($lines)],
        ));
    }

    /**
     * One translated line, in the chat's one language — the fallback.
     *
     * @param  array<string, string|int|float>  $replace
     */
    public function line(string $key, array $replace = []): string
    {
        $line = Lang::get($key, $replace, $this->localization->fallback());

        return is_string($line) ? $line : $key;
    }

    /**
     * @param  list<string|null>  $lines
     */
    private function text(array $lines): string
    {
        return implode("\n\n", array_filter(
            $lines,
            static fn (?string $line): bool => $line !== null && trim($line) !== '',
        ));
    }

    /**
     * The platform implementation this chat lives on.
     */
    private function platformFor(ChallengeChat $chat): MessengerPlatform
    {
        return $this->platforms->for($chat->platform);
    }

    /**
     * Ask the platform to send, gated on the chat still being ours to post to.
     *
     * A verdict of no from the lazy re-check is a quiet skip — the row is
     * deactivated and the creator told by `VerifyChallengeChat` itself, which
     * is the "notify once, don't retry-loop" contract. A verdict we could not
     * *obtain* propagates, so the job retries and asks again properly.
     *
     * @param  Closure(): SentMessage  $send
     *
     * @throws MessengerException when the platform cannot be asked or refuses
     */
    private function deliver(ChallengeChat $chat, Closure $send): bool
    {
        $outcome = $this->verifier->ensureFresh(
            $chat,
            $this->settings->integer(SettingKey::ChatVerificationTtlHours),
        );

        if ($outcome !== ChatLinkVerification::Verified) {
            return false;
        }

        $send();

        return true;
    }
}

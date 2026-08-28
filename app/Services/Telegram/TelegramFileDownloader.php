<?php

namespace App\Services\Telegram;

use App\Messaging\Contracts\MessengerException;
use App\Messaging\Telegram\TelegramMessengerPlatform;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;

/**
 * Pull a file out of the messenger and put it on our disk.
 *
 * The surface owns this, not `SubmitCheckIn`, and that split is deliberate: the
 * action takes a stored path precisely so the bot (a `file_id` fetched over the
 * Bot API) and the Mini App (an HTTP multipart upload) can share one submission
 * rule without the rule knowing either transport.
 *
 * The bytes come from the platform seam (`downloadFile`); this class adds only
 * the two Telegram payload shapes callers still hold — the photo ladder and the
 * voice object — and the storage convention shared by both.
 */
class TelegramFileDownloader
{
    public function __construct(
        private readonly TelegramMessengerPlatform $platform,
    ) {}

    /**
     * Download a photo array off a Telegram message and store it.
     *
     * @param  array<array-key, mixed>  $photo  Telegram's `message.photo`, as it
     *                                          arrived: untrusted, so unusable
     *                                          entries are filtered out here
     * @return string the stored path, as `SubmitCheckIn::uploadPhoto()` takes it
     *
     * @throws LogicException when the photo array carries nothing usable
     * @throws MessengerException when Telegram refuses the `getFile`
     * @throws RuntimeException when the bytes cannot be fetched or written
     */
    public function downloadPhoto(array $photo): string
    {
        $largest = collect($photo)
            ->filter(fn (mixed $size): bool => is_array($size) && is_string($size['file_id'] ?? null))
            ->sortByDesc(fn (array $size): int => (int) ($size['width'] ?? 0))
            ->first();

        if ($largest === null) {
            throw new LogicException('The photo array carries no size with a file_id.');
        }

        return $this->store($this->platform->downloadFile($largest['file_id']), 'jpg');
    }

    /**
     * Download a voice message off a Telegram message and store it.
     *
     * The *duration* is deliberately not this method's business: the surface
     * reads it from Telegram's own `voice.duration` and hands it to the step
     * action, which compares it against the step's cap — a duration we
     * re-fetched here could only ever be the same number with more steps.
     *
     * @param  array<array-key, mixed>  $voice  Telegram's `message.voice`, as it arrived
     *
     * @throws LogicException when the voice object carries no usable `file_id`
     * @throws MessengerException when Telegram refuses the `getFile`
     * @throws RuntimeException when the bytes cannot be fetched or written
     */
    public function downloadVoice(array $voice): string
    {
        $fileId = $voice['file_id'] ?? null;

        if (! is_string($fileId) || $fileId === '') {
            throw new LogicException('The voice object carries no file_id.');
        }

        // Telegram voice notes are Ogg Opus whatever the client labels them,
        // so the extension is fixed rather than parsed out of `mime_type`.
        return $this->store($this->platform->downloadFile($fileId), 'ogg');
    }

    /**
     * Store fetched bytes under the proof-storage convention.
     *
     * One directory per day keeps any single folder from growing without
     * bound, and a random name rather than the platform's keeps the stored
     * path from leaking anything about the sender's session.
     *
     * @throws RuntimeException when the bytes cannot be written
     */
    private function store(string $bytes, string $extension): string
    {
        $path = 'check-in-proofs/'.now()->format('Y/m/d').'/'.bin2hex(random_bytes(20)).'.'.$extension;

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException("The file could not be written to {$path}.");
        }

        return $path;
    }
}

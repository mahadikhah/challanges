<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use RuntimeException;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * Pull a file out of Telegram and put it on our disk.
 *
 * The surface owns this, not `SubmitCheckIn`, and that split is deliberate: the
 * action takes a stored path precisely so the bot (a Telegram `file_id` fetched
 * over the Bot API) and the Mini App (an HTTP multipart upload) can share one
 * submission rule without the rule knowing either transport.
 *
 * Telegram sends photos as a ladder of sizes; the largest is the one worth
 * keeping, because it is the one a creator zooms into when deciding whether the
 * proof counts.
 */
class TelegramFileDownloader
{
    /**
     * `getFile` answers with a path relative to the bot's own file store; the
     * bytes live at that path under a URL keyed by the token, which is why the
     * token never appears in anything we persist.
     */
    private const FILE_URL = 'https://api.telegram.org/file/bot';

    public function __construct(private readonly Api $telegram) {}

    /**
     * Download a photo array off a Telegram message and store it.
     *
     * @param  array<array-key, mixed>  $photo  Telegram's `message.photo`, as it
     *                                          arrived: untrusted, so unusable
     *                                          entries are filtered out here
     * @return string the stored path, as `SubmitCheckIn::uploadPhoto()` takes it
     *
     * @throws LogicException when the photo array carries nothing usable
     * @throws TelegramSDKException when Telegram refuses the `getFile`
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

        $file = $this->telegram->getFile(['file_id' => $largest['file_id']]);

        $filePath = $file->get('file_path');

        if (! is_string($filePath) || $filePath === '') {
            throw new RuntimeException('Telegram returned a file with no file_path.');
        }

        $bytes = Http::get(self::FILE_URL.$this->telegram->getAccessToken().'/'.$filePath)->body();

        if ($bytes === '') {
            throw new RuntimeException("The photo at {$filePath} could not be downloaded.");
        }

        // One directory per day keeps any single folder from growing without
        // bound, and a random name rather than Telegram's keeps the stored path
        // from leaking anything about the sender's session.
        $path = 'check-in-proofs/'.now()->format('Y/m/d').'/'.bin2hex(random_bytes(20)).'.jpg';

        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new RuntimeException("The photo could not be written to {$path}.");
        }

        return $path;
    }
}

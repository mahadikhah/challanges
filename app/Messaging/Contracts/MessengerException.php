<?php

namespace App\Messaging\Contracts;

use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * The one failure family shared code catches for messenger work.
 *
 * It extends the Telegram SDK's own exception on purpose, and the reason is
 * the seam this package lives in: every pre-existing caller and test already
 * catches `TelegramSDKException` on refusal paths, and a wrap-into-a-new-type
 * would silently turn those catches into dead code. This way a
 * `MessengerException` **is** the SDK exception those callers know, while new
 * shared code catches only `MessengerException` and stays platform-blind —
 * Bale's implementation will subclass this, not the Telegram type.
 */
class MessengerException extends TelegramSDKException
{
    /**
     * Carry a platform SDK failure across the seam without losing it.
     */
    public static function fromTelegram(TelegramSDKException $failure): self
    {
        return new self($failure->getMessage(), $failure->getCode(), $failure);
    }
}

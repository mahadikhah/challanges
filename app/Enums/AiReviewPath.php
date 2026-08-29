<?php

namespace App\Enums;

/**
 * How a media proof reached the model — the audit answer to "what did the
 * AI actually look at?".
 *
 * `attachment` is the media sent as-is (a photo today; video bytes when Task
 * 4 ships them). `transcript` is the voice path: no driver in the catalog
 * accepts audio as a prompt attachment, so a voice recording is transcribed
 * first and the *transcript* is what gets judged (Phase 14 Task 3).
 */
enum AiReviewPath: string
{
    case Attachment = 'attachment';
    case Transcript = 'transcript';
}

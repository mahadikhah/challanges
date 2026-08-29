<?php

namespace App\Enums;

/**
 * How a media proof reached the model — the audit answer to "what did the
 * AI actually look at?".
 *
 * `attachment` is the media sent as-is (a photo today; video bytes when a
 * catalog driver that accepts them joins). `transcript` is the voice path:
 * no driver in the catalog accepts audio as a prompt attachment, so a voice
 * recording is transcribed first and the *transcript* is what gets judged
 * (Phase 14 Task 3). `frames` is the video path: no catalog driver accepts
 * video bytes either, so a fixed handful of evenly-spaced stills is
 * extracted and judged as one set (Phase 14 Task 4).
 */
enum AiReviewPath: string
{
    case Attachment = 'attachment';
    case Transcript = 'transcript';
    case Frames = 'frames';
}

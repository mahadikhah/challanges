<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * What a session step demands of the participant once its wait has elapsed.
 *
 * The button/image/voice triple is deliberately coarser than the platform's
 * proof types: a step is an ingredient of a check-in, not a check-in, and it
 * never reaches a settlement on its own — the completed session does.
 */
enum StepInputType: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /** A tap; the wait is the only substance. */
    case Button = 'button';

    /** A photo, stored through the same path as image-approval proofs. */
    case Image = 'image';

    /** A voice message, length-capped by the step's `voice_max_seconds`. */
    case Voice = 'voice';
}

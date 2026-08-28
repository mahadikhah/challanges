<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * What became of one AI moderation call.
 *
 * `Applied` — the model answered with confidence at or above the threshold, so
 * its verdict settled the check-in immediately through the same paths a manual
 * review uses.
 *
 * `FellBack` — low confidence, a response that could not be read as the locked
 * shape, or no provider answered at all. The check-in stays in the manual
 * queue, exactly as if AI review did not exist.
 *
 * One row is written for **every** call, including the fallbacks — the audit
 * trail is the point (§2.8), and "the AI silently did nothing" is not an
 * auditable outcome.
 */
enum AiDecisionOutcome: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case Applied = 'applied';
    case FellBack = 'fell_back';
}

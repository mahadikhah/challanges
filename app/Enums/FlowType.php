<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * How a challenge's check-ins are collected.
 *
 * Orthogonal to `ProofType`, which stays meaningful in both flows: a
 * `timed_session` challenge still has a proof type, and the session's final
 * settlement records evidence of that type. What the flow decides is the
 * *shape of the ritual* — one submission, or a gated sequence of steps.
 */
enum FlowType: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    /** One submission per period: a tap, a phrase, or a photo for review. */
    case Simple = 'simple';

    /** Start → gated steps → end; completion feeds the ordinary settlement. */
    case TimedSession = 'timed_session';
}

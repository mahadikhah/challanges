<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * How one outbound call to an outside system ended.
 *
 * Success means the provider answered; whether the answer was the one the
 * caller wanted is the caller's business, not the counter's. Failure means
 * the call did not complete — refused, timed out, unreachable.
 */
enum ExternalCallOutcome: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case Success = 'success';

    case Failure = 'failure';
}

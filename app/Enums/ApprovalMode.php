<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;
use App\Enums\Contracts\HasTranslatedLabel as HasTranslatedLabelContract;

/**
 * Who answers an `image_approval` submission: the creator, or the model.
 *
 * `ai` is only reachable through a stored, screened `approval_criteria` —
 * the Action and the Form Request both enforce that; the enum says what the
 * mode *is*, the gate says when it may be *chosen*.
 */
enum ApprovalMode: string implements HasTranslatedLabelContract
{
    use HasTranslatedLabel;

    case Manual = 'manual';

    case Ai = 'ai';
}

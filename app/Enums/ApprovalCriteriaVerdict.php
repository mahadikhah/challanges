<?php

namespace App\Enums;

/**
 * What the criteria screening call concluded about a piece of creator text.
 */
enum ApprovalCriteriaVerdict: string
{
    case Clean = 'clean';

    case Flagged = 'flagged';

    /**
     * The provider never answered (outage, timeout, nothing configured). The
     * text is neither trusted nor condemned — the challenge falls back to
     * manual, exactly as a flagged text does, but the log row says why.
     */
    case Unscreened = 'unscreened';
}

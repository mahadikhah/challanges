<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * The state of one participant's obligation for one period.
 *
 * The intended path:
 *
 *   Pending ──(button / matching phrase)──────────────► Approved
 *   Pending ──(photo uploaded)──► Submitted ──(creator)─► Approved
 *                                     └──(creator)─────► Rejected ──► Submitted
 *   …anything still unapproved when the period closes ─► Frozen | Missed
 *
 * `Rejected` is deliberately *not* an ending. While the period is still open a
 * participant may upload a better photo, and only the rollover — which converts
 * an unapproved row to `Frozen` if a freeze is available, otherwise `Missed` —
 * decides whether the period was actually lost. That keeps one rule in one
 * place: the streak engine only ever has to look for `Missed`.
 */
enum CheckInStatus: string
{
    use HasTranslatedLabel;

    /**
     * The period is open and nothing has been submitted yet.
     */
    case Pending = 'pending';

    /**
     * A photo is waiting on the creator.
     */
    case Submitted = 'submitted';

    /**
     * Done. Counts towards the streak.
     */
    case Approved = 'approved';

    /**
     * The creator turned this photo down. Resubmission is allowed until the
     * period closes.
     */
    case Rejected = 'rejected';

    /**
     * The period closed unapproved and no freeze was available.
     */
    case Missed = 'missed';

    /**
     * The period closed unapproved and a freeze absorbed it.
     */
    case Frozen = 'frozen';

    /**
     * Whether the outcome is final — set by the rollover, or by an approval.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Approved, self::Missed, self::Frozen], true);
    }

    /**
     * Whether the participant may still submit proof.
     */
    public function allowsSubmission(): bool
    {
        return in_array($this, [self::Pending, self::Rejected], true);
    }

    /**
     * Whether this row belongs in the creator's review queue.
     */
    public function awaitsReview(): bool
    {
        return $this === self::Submitted;
    }

    /**
     * Whether this period adds to the streak. A freeze protects a streak but
     * does not extend it: the participant did not do the thing, they bought
     * their way out of the penalty.
     */
    public function incrementsStreak(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Whether the streak survives this period.
     */
    public function preservesStreak(): bool
    {
        return in_array($this, [self::Approved, self::Frozen], true);
    }

    /**
     * Whether the streak resets to zero because of this period.
     */
    public function breaksStreak(): bool
    {
        return $this === self::Missed;
    }

    /**
     * Whether settling into this status spends one of the participant's freezes.
     *
     * The defining property of a freeze, and the reason the streak survives it.
     */
    public function consumesFreeze(): bool
    {
        return $this === self::Frozen;
    }
}

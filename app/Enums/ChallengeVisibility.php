<?php

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * Who can discover a challenge.
 *
 * Public challenges are auto-posted to the announcement channel; invite-only
 * ones are reachable solely through their creator's link.
 */
enum ChallengeVisibility: string
{
    use HasTranslatedLabel;

    case Public = 'public';
    case InviteOnly = 'invite_only';

    /**
     * Whether this challenge belongs in the announcement channel.
     *
     * Announcing is separately guarded by `Challenge::$announced_at`, so a
     * re-run cannot double-post.
     */
    public function shouldAnnounce(): bool
    {
        return $this === self::Public;
    }
}

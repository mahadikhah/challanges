<?php

namespace App\Services\Ai;

use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Services\Settings;

/**
 * Whether AI approval exists for a media type, as this deployment's admin has
 * configured it.
 *
 * The §2.11 split in one place: the admin owns *availability* — the global
 * switch and one switch per media type — and the creator owns *content*: their
 * challenge's criteria text and the manual-vs-ai choice among the types the
 * admin allowed. Every consumer of that boundary asks this class, so the
 * two-switch rule (global **and** per-type) cannot drift between the creation
 * gate, the review router, and whatever surface is offering the option.
 *
 * Phase 14 tightened Phase 10 deliberately: image AI-approval was creator-
 * self-service once criteria passed screening; now every media type is
 * admin-opt-in, and image is merely the first allowed.
 */
class AiApprovalGate
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Whether `$proofType` may be reviewed by AI on this deployment. A
     * non-media proof type is never AI-reviewed, so it is a plain no rather
     * than an exception: callers gate UI options with this answer.
     */
    public function allows(ProofType $proofType): bool
    {
        $perType = $proofType->aiApprovalSetting();

        return $perType !== null
            && $this->settings->boolean(SettingKey::AiApprovalGloballyEnabled)
            && $this->settings->boolean($perType);
    }
}

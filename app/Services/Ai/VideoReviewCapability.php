<?php

namespace App\Services\Ai;

use App\Models\AiCapability;
use App\Models\AiProviderAccount;
use Illuminate\Support\Collection;

/**
 * Can this deployment review video proof with AI at all?
 *
 * The honest answer needs two different things to be true (§2.11,
 * "verify, don't assume"):
 *
 * - **Something to judge with** — an active, configured account on the
 *   `proof_moderation` capability, the same thing image review rests on.
 * - **A way to get the video to the model.** Verified against the SDK
 *   gateways: no driver in our catalog accepts video bytes as a prompt
 *   attachment — only Gemini's gateway maps video, and Gemini is not in
 *   `AiDriverCatalog`. So the only live route today is extracting a few
 *   evenly-spaced frames with ffmpeg and judging those as a set, which
 *   demands the binaries actually be present in this environment (they
 *   typically are not on shared hosting).
 *
 * The admin settings panel and the runtime verdict router both ask this
 * class, so "the toggle looked enabled" and "a submission was actually
 * reviewed" can never disagree about what the environment supports.
 */
class VideoReviewCapability
{
    public function __construct(private readonly FfmpegDetector $ffmpeg) {}

    /**
     * Whether video AI review can run here and now: something to judge with,
     * and a way to get the video to it.
     */
    public function available(): bool
    {
        return $this->unavailableReason() === null;
    }

    /**
     * Why not, in the admin panel's own words — null when it is available.
     *
     * Both halves are reported separately because they have different
     * remedies: a missing provider is an admin's to fix, a missing ffmpeg is
     * the host's.
     */
    public function unavailableReason(): ?string
    {
        if ($this->moderationAccounts()->isEmpty()) {
            return 'no_provider';
        }

        if ($this->nativeVideoAccount() === null && ! $this->ffmpeg->present()) {
            return 'no_toolchain';
        }

        return null;
    }

    /**
     * A configured moderation account whose driver would accept video bytes
     * directly, or null.
     *
     * Empty list on purpose: no catalog driver maps video today. The day one
     * joins (Gemini's gateway does), it is added here and in the catalog's
     * driver registry, and the ffmpeg requirement quietly stops applying to
     * deployments using it.
     */
    private function nativeVideoAccount(): ?AiProviderAccount
    {
        return $this->moderationAccounts()
            ->first(fn (AiProviderAccount $account): bool => AiDriverCatalog::supportsVideoInput((string) $account->driver));
    }

    /**
     * @return Collection<int, AiProviderAccount>
     */
    private function moderationAccounts()
    {
        return AiProviderAccount::query()
            ->where('ai_provider_accounts.is_active', true)
            ->whereHas('capability', fn ($capability) => $capability
                ->where('key', AiCapability::KEY_PROOF_MODERATION)
                ->where('is_active', true))
            ->get()
            ->filter(fn (AiProviderAccount $account): bool => $account->isConfigured());
    }
}

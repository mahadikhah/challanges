<?php

namespace App\Actions\Ai;

use App\Enums\AiDecisionOutcome;
use App\Enums\AiReviewPath;
use App\Enums\ProofType;
use App\Enums\SettingKey;
use App\Models\AiApprovalDecision;
use App\Models\AiCapability;
use App\Models\Challenge;
use App\Models\CheckIn;
use App\Services\Ai\AiOperationIdentity;
use App\Services\Ai\AiTextClient;
use App\Services\Ai\AiTextResult;
use App\Services\Settings;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * One AI moderation call on one submitted media proof.
 *
 * This action only *asks and records*. Applying the verdict is the routing
 * action's job, which is what keeps AI review honest: the model's answer is a
 * boolean gate into the ordinary settlement paths, never a code path of its
 * own. `SettleCheckIn` does not know AI review exists.
 *
 * The prompt — the trust boundary of the whole feature (§2.8) — is built by
 * `BuildProofModerationPrompt`, the one clearly-named place to look. Here we
 * only pass what it built through the provider chain on the
 * `proof_moderation` capability, with the media attached.
 *
 * The media kind comes from the challenge's proof type, and with it the path
 * the proof takes to the model (§2.11, "verify, don't assume"): a photo is
 * attached as-is; a voice recording cannot be — no driver in the catalog
 * accepts audio as a prompt attachment — so it is transcribed first and the
 * transcript is judged as fenced text. The row records which path ran.
 *
 * Never throws. Every outcome — a confident verdict, a hedge, an unreadable
 * response, a total provider failure — is written as one `AiApprovalDecision`
 * row, because a fallback that leaves no trace is indistinguishable from AI
 * review silently not existing. The routing action reads the row and decides.
 */
class ReviewProofWithAi
{
    /**
     * The audit row stores what the model said, bounded — a runaway response
     * is not worth a runaway row, and the first chunk carries the verdict.
     */
    private const RAW_RESPONSE_MAX = 2000;

    public function __construct(
        private readonly RunAiProviderChainAction $chain,
        private readonly AiTextClient $clients,
        private readonly BuildProofModerationPrompt $prompt,
        private readonly Settings $settings,
    ) {}

    /**
     * Ask the provider chain for a verdict on the submitted proof.
     *
     * `$challenge` is the row the criteria comes from — deliberately read
     * from the challenge, never from anything the caller might have
     * supplied as "the criteria", so there is exactly one place the
     * untrusted text can enter the prompt, and it is fenced there.
     */
    public function review(CheckIn $submission, Challenge $challenge): AiApprovalDecision
    {
        $proofType = $challenge->proof_type;
        $transcript = null;

        try {
            $answer = ($this->chain)(
                AiCapability::KEY_PROOF_MODERATION,
                AiOperationIdentity::create(AiCapability::KEY_PROOF_MODERATION, $submission->getKey()),
                function (string $connection, ?string $model) use ($submission, $challenge, $proofType, &$transcript): AiTextResult {
                    // The voice leg runs inside the chain's attempt on
                    // purpose: the transcription belongs to the same account,
                    // the same rotation, and the same budget reservation as
                    // the verdict it feeds. A provider that cannot transcribe
                    // fails the attempt and the chain rotates accounts — a
                    // transcription the verdict never sees is worth nothing.
                    $transcript = $proofType === ProofType::VoiceApproval
                        ? $this->transcribe($connection, (string) $submission->proof_path)
                        : null;

                    return $this->clients->prompt(
                        $connection,
                        $model,
                        $transcript !== null
                            ? $this->prompt->transcriptPrompt($challenge, $transcript)
                            : $this->prompt->userPrompt($challenge),
                        [
                            'system' => $this->prompt->systemPrompt($proofType),
                            'schema' => fn ($schema): array => $this->prompt->lockedVerdictSchema($schema),
                            'image' => $proofType === ProofType::ImageApproval ? [
                                'path' => (string) $submission->proof_path,
                                'disk' => 'local',
                            ] : null,
                        ],
                    );
                },
                $submission,
            );

            [$approved, $confidence, $reason] = $this->readVerdict($answer->structured);

            return AiApprovalDecision::query()->create([
                'check_in_id' => $submission->getKey(),
                'connection' => $answer->connection,
                'model' => $answer->model,
                'review_path' => $proofType === ProofType::VoiceApproval
                    ? AiReviewPath::Transcript
                    : AiReviewPath::Attachment,
                'outcome' => $this->confidenceClearsThreshold($confidence)
                    ? AiDecisionOutcome::Applied
                    : AiDecisionOutcome::FellBack,
                'approved' => $approved,
                'confidence' => $confidence,
                'reason' => $reason,
                'transcript' => $proofType === ProofType::VoiceApproval
                    ? $this->truncate($transcript)
                    : null,
                'latency_ms' => $answer->durationMs,
                'raw_response' => $this->truncate($answer->text),
            ]);
        } catch (Throwable $unanswered) {
            // Capability off, nothing configured, or every account failed.
            // The submission stays in the manual queue — routing to a human
            // is the only safe answer to a question nobody answered.
            Log::warning('AI proof review could not reach a provider.', [
                'check_in_id' => $submission->getKey(),
                'reason' => $unanswered::class,
            ]);

            return AiApprovalDecision::query()->create([
                'check_in_id' => $submission->getKey(),
                'outcome' => AiDecisionOutcome::FellBack,
                'approved' => null,
                'confidence' => null,
                'reason' => $unanswered::class,
            ]);
        }
    }

    /**
     * Turn the stored recording into the text the verdict will judge.
     *
     * An empty transcript is not walked further: asking a verdict about a
     * recording nobody could hear is how "the STT endpoint returned an empty
     * body" becomes an approval. Throwing here fails the attempt, and the
     * chain's accounts — or the manual queue — answer instead.
     */
    private function transcribe(string $connection, string $path): string
    {
        $transcript = trim($this->clients->transcribe($connection, $path));

        if ($transcript === '') {
            throw new RuntimeException('The voice recording produced an empty transcript.');
        }

        return $transcript;
    }

    /**
     * Read the structured verdict, refusing anything not in the locked shape.
     *
     * The schema forces `approved` and `confidence` to exist with the right
     * types; the guards are for the seam between "the gateway decoded JSON"
     * and "the array matches what we asked for" — an unparseable or
     * shape-shifting response falls back rather than being interpreted
     * generously. `reason` is a display string from here on: it is stored,
     * truncated, and never read again by any code.
     *
     * @param  array<string, mixed>  $structured
     * @return array{0: bool|null, 1: float|null, 2: string|null}
     */
    private function readVerdict(array $structured): array
    {
        $approved = $structured['approved'] ?? null;
        $confidence = $structured['confidence'] ?? null;
        $reason = $structured['reason'] ?? null;

        if (! is_bool($approved) || ! is_numeric($confidence)) {
            return [null, null, 'The response did not match the locked verdict shape.'];
        }

        $confidence = (float) $confidence;

        if ($confidence < 0 || $confidence > 100) {
            return [null, null, 'The claimed confidence is outside 0–100.'];
        }

        return [$approved, $confidence, is_string($reason) ? $this->truncate($reason) : null];
    }

    /**
     * The admin-configured bar a verdict must clear to act on itself.
     *
     * A null confidence never clears anything — an answer the model would not
     * stand behind is a human's to make.
     */
    private function confidenceClearsThreshold(?float $confidence): bool
    {
        if ($confidence === null) {
            return false;
        }

        return $confidence >= $this->settings->integer(SettingKey::AiApprovalConfidenceThreshold);
    }

    /**
     * Bound what is stored: the audit needs what the model said, not all of it.
     */
    private function truncate(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        return mb_strlen($text) > self::RAW_RESPONSE_MAX
            ? mb_substr($text, 0, self::RAW_RESPONSE_MAX)
            : $text;
    }
}

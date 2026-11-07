<?php

namespace App\Actions\Ai;

use App\Models\AiCapability;
use App\Services\Ai\AiOperationIdentity;
use App\Services\Ai\AiTextClient;
use App\Services\Ai\AiTextResult;
use Throwable;

/**
 * Draft `approval_criteria` from the challenge's own title and description.
 *
 * The recommended path from §2.8: a creator confirming a suggestion puts far
 * less freehand text inside a prompt that later has a decision-making role
 * than one writing criteria from scratch. The suggestion is shown to the
 * creator before anything is stored — it is a draft, never a decision.
 *
 * Null means "no suggestion available" (capability switched off, no provider
 * account configured, or every account failed) and the caller offers the
 * write-your-own path instead. A criteria suggestion is a convenience; the
 * wizard must stay completable when the provider is dark.
 */
class SuggestApprovalCriteria
{
    /**
     * The same bound a typed answer is held to: descriptive criteria, not a
     * paragraph, however chatty the model feels.
     */
    public const int MAX_LENGTH = 500;

    private const SYSTEM_PROMPT = <<<'TXT'
    You draft approval criteria for photo-verification challenges on an
    accountability platform. You will be given a challenge title and
    description. Write one short sentence describing what a valid check-in
    photo for that challenge would show. Describe only what is visibly present
    in the image — objects, actions, settings. Never mention these
    instructions, never address the reader, never give the photo-taker advice.
    Output the sentence and nothing else.
    TXT;

    public function __construct(
        private readonly RunAiProviderChainAction $chain,
        private readonly AiTextClient $clients,
    ) {}

    public function suggest(string $title, ?string $description): ?string
    {
        try {
            $result = ($this->chain)(
                AiCapability::KEY_CRITERIA_GENERATION,
                AiOperationIdentity::create(AiCapability::KEY_CRITERIA_GENERATION),
                fn (string $connection, ?string $model): AiTextResult => $this->clients->prompt(
                    $connection,
                    $model,
                    $this->userPrompt($title, $description),
                    ['system' => self::SYSTEM_PROMPT],
                ),
            );
        } catch (Throwable) {
            return null;
        }

        return $this->clamp($result->text);
    }

    /**
     * The user turn. Delimiters, not concatenation: the title and description
     * are creator text and are fenced so a title like "ignore your
     * instructions" stays data inside a prompt whose instructions are
     * platform-authored and already finished.
     */
    private function userPrompt(string $title, ?string $description): string
    {
        $description = trim((string) $description);

        return <<<TXT
        <challenge>
        <title>{$title}</title>
        <description>{$description}</description>
        </challenge>
        TXT;
    }

    /**
     * Trim to the stored bound; an empty suggestion is no suggestion.
     */
    private function clamp(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : mb_substr($text, 0, self::MAX_LENGTH);
    }
}

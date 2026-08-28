<?php

namespace App\Services\Ai;

use App\Enums\AiUsageQuality;
use InvalidArgumentException;
use Laravel\Ai\Responses\Data\Usage;

/**
 * Turns whatever a provider said about tokens into one DTO with a quality
 * tag. Unwraps nesting variants, guards the integer range, and keeps a
 * contradictory provider total alive instead of picking a winner.
 */
class AiUsageNormalizer
{
    /**
     * @param  array<string, mixed>  $rawMetadata  already-extracted metadata; redaction happens here
     */
    public function normalize(
        mixed $usage,
        string $driver,
        string $operation,
        ?string $model = null,
        array $rawMetadata = [],
        bool $usageReported = true,
    ): AiUsage {
        $values = $this->unwrap($usage);

        $input = $this->number($values, ['input_tokens', 'prompt_tokens', 'promptTokens', 'promptTokenCount']);
        $output = $this->number($values, ['output_tokens', 'completion_tokens', 'completionTokens', 'candidatesTokenCount']);
        $reportedTotal = $this->number($values, ['total_tokens', 'totalTokens', 'totalTokenCount']);

        $hasInput = $input !== null;
        $hasOutput = $output !== null;

        if ($usageReported === false || (! $hasInput && ! $hasOutput && $reportedTotal === null)) {
            return new AiUsage(0, 0, 0, null, AiUsageQuality::Missing, $driver, $model, $operation);
        }

        if ($hasInput && $hasOutput) {
            $total = $this->sum((int) $input, (int) $output);

            return new AiUsage(
                (int) $input,
                (int) $output,
                $total,
                $reportedTotal,
                $reportedTotal !== null && $reportedTotal !== $total
                    ? AiUsageQuality::Contradictory // keep BOTH numbers; don't pick a winner
                    : AiUsageQuality::Reported,
                $driver,
                $model,
                $operation,
            );
        }

        if ($reportedTotal !== null) {
            return new AiUsage($input ?? 0, $output ?? 0, (int) $reportedTotal, (int) $reportedTotal, AiUsageQuality::Estimated, $driver, $model, $operation);
        }

        return new AiUsage($input ?? 0, $output ?? 0, $this->sum($input ?? 0, $output ?? 0), null, AiUsageQuality::Estimated, $driver, $model, $operation);
    }

    /**
     * Providers nest differently and change between versions: accept a native
     * usage object, `['usage' => [...]]`, `['usageMetadata' => [...]]`, a
     * bare array, or a plain object.
     *
     * @return array<string, mixed>
     */
    private function unwrap(mixed $usage): array
    {
        if ($usage === null) {
            return [];
        }

        if ($usage instanceof Usage) {
            return $usage->toArray();
        }

        if (is_object($usage)) {
            $usage = (array) $usage;
        }

        if (! is_array($usage)) {
            return [];
        }

        foreach (['usage', 'usageMetadata'] as $wrapper) {
            if (isset($usage[$wrapper]) && (is_array($usage[$wrapper]) || is_object($usage[$wrapper]))) {
                $inner = (array) $usage[$wrapper];

                // Preserve siblings like `model` at the outer level.
                return array_merge(array_diff_key($usage, [$wrapper => true]), $inner);
            }
        }

        return $usage;
    }

    /**
     * First matching key, as a non-negative integer inside PHP's range. A
     * provider echoing a garbage token count raises rather than wrapping to
     * a negative.
     */
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     */
    private function number(array $values, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $values) || $values[$key] === null) {
                continue;
            }

            $value = $values[$key];

            if (is_bool($value) || (! is_int($value) && ! is_float($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1))) {
                throw new InvalidArgumentException("The token count for [{$key}] is not numeric.");
            }

            $int = (int) $value;

            if ($int < 0) {
                throw new InvalidArgumentException("The token count for [{$key}] is negative.");
            }

            if ($int > PHP_INT_MAX) {
                throw new InvalidArgumentException("The token count for [{$key}] exceeds the integer range.");
            }

            return $int;
        }

        return null;
    }

    private function sum(int $a, int $b): int
    {
        if ($a > PHP_INT_MAX - $b) {
            throw new InvalidArgumentException('The summed token count exceeds the integer range.');
        }

        return $a + $b;
    }
}

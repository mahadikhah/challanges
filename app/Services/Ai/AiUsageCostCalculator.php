<?php

namespace App\Services\Ai;

use App\Models\AiProviderAccount;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Integer minor units, one currency, arbitrary-precision arithmetic.
 *
 * `tokens * rate` overflows 64-bit at plausible volumes and floats drift;
 * neither is acceptable in an accounting column. Round half up explicitly —
 * don't inherit whatever the language's default rounding is. `null` ≠ 0: an
 * account with no price yields a null cost, reported as "unknown cost", and
 * the rates are snapshotted onto the record so a price change never rewrites
 * history.
 */
class AiUsageCostCalculator
{
    /**
     * @return array{input_rate_per_million: int|null, output_rate_per_million: int|null, cost_minor: int|null}
     */
    public function calculate(AiUsage $usage, ?AiProviderAccount $account): array
    {
        $inputRate = $account?->input_token_price_per_million;
        $outputRate = $account?->output_token_price_per_million;
        $cost = null;

        // Only price it when a rate exists for every dimension ACTUALLY USED.
        if (($usage->inputTokens === 0 || $inputRate !== null)
            && ($usage->outputTokens === 0 || $outputRate !== null)
            && ($usage->inputTokens > 0 || $usage->outputTokens > 0)) {
            $cost = $this->component($usage->inputTokens, $inputRate)
                + $this->component($usage->outputTokens, $outputRate);
        }

        return [
            'input_rate_per_million' => $inputRate === null ? null : (int) $inputRate,
            'output_rate_per_million' => $outputRate === null ? null : (int) $outputRate,
            'cost_minor' => $cost,
        ];
    }

    private function component(int $tokens, ?int $rate): int
    {
        return $tokens === 0 || $rate === null
            ? 0
            : BigInteger::of($tokens)
                ->multipliedBy($rate)
                ->plus(500_000) // round half up
                ->dividedBy(1_000_000, RoundingMode::Down)
                ->toInt();
    }
}

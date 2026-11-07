<?php

namespace App\Services\Ai;

use App\Enums\AiLimitScope;

/**
 * The budget decision for one reservation attempt.
 *
 * `remaining` is the tightest finite headroom across dimensions and scopes;
 * `remainingByScope` is kept so that a refused call answers the operator's
 * first question — "which limit?" — without a second query.
 */
final readonly class AiLimitDecision
{
    /**
     * @param  array<string, int|null>  $remainingByScope  "<dimension>.<scope>" => tokens left (null = unlimited)
     */
    public function __construct(
        public bool $allowed,
        public ?int $remaining,
        public array $remainingByScope,
        public ?string $reason = null,
    ) {}

    /**
     * The scopes that actually bound this call.
     *
     * @return list<AiLimitScope>
     */
    public function bindingScopes(): array
    {
        $scopes = [];

        foreach ($this->remainingByScope as $key => $remaining) {
            if ($remaining === null || $remaining > 0) {
                continue;
            }

            $scope = str_ends_with($key, '.'.AiLimitScope::Global->value)
                ? AiLimitScope::Global
                : AiLimitScope::Account;

            if (! in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }
}

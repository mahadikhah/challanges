<?php

namespace App\Actions\MiniApp;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What a successful Mini App authentication leaves behind: who, with what
 * bearer token, until when.
 */
final readonly class MiniAppSession
{
    public function __construct(
        public User $user,
        public string $token,
        public CarbonImmutable $expiresAt,
    ) {}
}

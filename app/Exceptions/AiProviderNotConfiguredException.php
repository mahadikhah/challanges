<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Nothing callable exists for the capability: no active account, or none
 * complete enough to call. Thrown before anything is spent or recorded.
 */
class AiProviderNotConfiguredException extends RuntimeException
{
    public static function forCapability(string $key): self
    {
        return new self("No AI provider account is configured for the [{$key}] capability.");
    }
}

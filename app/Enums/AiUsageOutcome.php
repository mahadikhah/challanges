<?php

namespace App\Enums;

/**
 * Why an attempt ended — diagnosis, not accounting. Deliberately a separate
 * axis from `AiUsageRecordStatus`: `succeeded` + `missing_usage` is a real,
 * common combination (the provider answered but said nothing about tokens).
 */
enum AiUsageOutcome: string
{
    case Completed = 'completed';
    case ProviderFailed = 'provider_failed';
    case ParseFailed = 'parse_failed';
    case MissingUsage = 'missing_usage';
    case Retry = 'retry';
}

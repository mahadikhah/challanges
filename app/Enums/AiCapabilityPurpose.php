<?php

namespace App\Enums;

/**
 * What kind of work a capability asks a provider to do.
 *
 * Gates which drivers may be offered for an account: a driver is only a
 * candidate when its provider class implements the matching SDK contract, so
 * an embedding-only vendor never appears in a picker only to fail at call time.
 */
enum AiCapabilityPurpose: string
{
    case Text = 'text';
    case Vision = 'vision';
}

<?php

namespace App\Enums;

/**
 * Which budget a number belongs to: the account's own limits or the app-wide
 * (global) window. A refused call names its scope so the caller can tell
 * "try the next account" (account) from "give up" (global).
 */
enum AiLimitScope: string
{
    case Account = 'account';
    case Global = 'global';
}

<?php

return [

    /*
    |----------------------------------------------------------------------
    | Currency for AI cost accounting
    |----------------------------------------------------------------------
    |
    | Integer minor units everywhere (never floats). Prices live per-account
    | in the database; this only names the unit the ledger is kept in.
    |
    */

    'currency' => ['code' => 'USD', 'minor_unit' => 100],

    /*
    |----------------------------------------------------------------------
    | Token estimates per operation
    |----------------------------------------------------------------------
    |
    | Budgets are enforced on these estimates BEFORE the provider is called —
    | you cannot enforce a budget with numbers you only learn after spending
    | them. They are tuning knobs, hence config, not DB rows.
    |
    */

    'estimates' => [
        'criteria_generation' => ['input_tokens' => 1_500, 'output_tokens' => 600, 'total_tokens' => 2_100],
        'criteria_screening' => ['input_tokens' => 1_200, 'output_tokens' => 200, 'total_tokens' => 1_400],
        'proof_moderation' => ['input_tokens' => 2_500, 'output_tokens' => 300, 'total_tokens' => 2_800],
    ],
];

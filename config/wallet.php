<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute, per authenticated user. Each limiter has its own
    | counter, so reading balances never uses up the transfer budget.
    |
    */

    'rate_limits' => [
        'transfers_per_minute' => (int) env('WALLET_TRANSFERS_PER_MINUTE', 10),
        'reads_per_minute' => (int) env('WALLET_READS_PER_MINUTE', 60),
    ],

];

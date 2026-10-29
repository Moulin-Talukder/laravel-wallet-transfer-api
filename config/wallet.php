<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limits
    |--------------------------------------------------------------------------
    |
    | Requests per minute. Transfers and reads are counted per authenticated
    | user; login attempts per email and IP address. Each limiter has its own
    | counter, so reading balances never uses up the transfer budget.
    |
    */

    'rate_limits' => [
        'transfers_per_minute' => (int) env('WALLET_TRANSFERS_PER_MINUTE', 10),
        'reads_per_minute' => (int) env('WALLET_READS_PER_MINUTE', 60),
        'login_attempts_per_minute' => (int) env('WALLET_LOGIN_ATTEMPTS_PER_MINUTE', 5),
    ],

];

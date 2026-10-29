<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Only the external frontend. Browsers send Origin without a trailing slash.
    'allowed_origins' => [rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // Lets the frontend read replay and rate-limit information from responses.
    'exposed_headers' => ['Idempotent-Replayed', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'],

    'max_age' => 0,

    'supports_credentials' => false,

];

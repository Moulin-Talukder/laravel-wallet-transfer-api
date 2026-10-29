<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CorsTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND = 'http://localhost:3000';

    public function test_preflight_from_the_frontend_origin_returns_204_with_allow_headers(): void
    {
        $this->options('/api/transfers', [], [
            'Origin' => self::FRONTEND,
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type, idempotency-key',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND)
            ->assertHeader('Access-Control-Allow-Methods', 'POST')
            ->assertHeader('Access-Control-Allow-Headers', 'authorization, content-type, idempotency-key');
    }

    /**
     * With a single allowed origin the header always names the frontend, whatever the
     * request's Origin. The browser compares it with its own origin and blocks the response.
     */
    public function test_preflight_from_another_origin_is_only_allowed_for_the_frontend(): void
    {
        $this->options('/api/transfers', [], [
            'Origin' => 'https://evil.example',
            'Access-Control-Request-Method' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);
    }

    public function test_responses_to_the_frontend_are_readable_and_expose_replay_and_rate_limit_headers(): void
    {
        $this->postJson('/api/login', [], ['Origin' => self::FRONTEND])
            ->assertUnprocessable()
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND)
            ->assertHeader('Access-Control-Expose-Headers', 'Idempotent-Replayed, Retry-After, X-RateLimit-Limit, X-RateLimit-Remaining');
    }

    public function test_responses_to_another_origin_are_only_allowed_for_the_frontend(): void
    {
        $this->postJson('/api/login', [], ['Origin' => 'https://evil.example'])
            ->assertHeader('Access-Control-Allow-Origin', self::FRONTEND);
    }
}

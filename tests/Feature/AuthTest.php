<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_login_with_valid_credentials_returns_200_with_24_hour_bearer_token(): void
    {
        $this->freezeTime();
        $wallet = Wallet::factory()->for(User::factory()->state([
            'email' => 'alice@example.com',
            'password' => self::PASSWORD,
        ]))->create();

        $response = $this->postJson('/api/login', ['email' => ' Alice@Example.com ', 'password' => self::PASSWORD]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_at', now()->addHours(24)->toIso8601String());
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $wallet->user->id,
            'name' => 'frontend',
        ]);

        $this->getJson('/api/wallet', ['Authorization' => 'Bearer '.$response->json('token')])
            ->assertOk();
    }

    public function test_me_returns_200_with_only_name_and_email(): void
    {
        $user = User::factory()->create(['name' => 'Alice Rahman', 'email' => 'alice@example.com']);
        $token = $user->createToken('frontend')->plainTextToken;

        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertExactJson(['data' => ['name' => 'Alice Rahman', 'email' => 'alice@example.com']]);
    }

    public function test_logout_returns_204_and_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $loggingOut = $user->createToken('laptop')->plainTextToken;
        $otherDevice = $user->createToken('phone')->plainTextToken;

        $this->postJson('/api/logout', [], ['Authorization' => 'Bearer '.$loggingOut])
            ->assertNoContent();

        // The guard caches the resolved user between requests within one test.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$loggingOut])->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me', ['Authorization' => 'Bearer '.$otherDevice])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'laptop']);
        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'phone']);
    }

    public function test_logout_and_me_return_401_without_a_token(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_login_with_wrong_password_returns_422_with_generic_message(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Invalid credentials.',
                'errors' => ['email' => ['Invalid credentials.']],
            ]);

        $this->assertDatabaseEmpty('personal_access_tokens');
    }

    public function test_login_with_unknown_email_returns_422_with_the_same_generic_message(): void
    {
        $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Invalid credentials.',
                'errors' => ['email' => ['Invalid credentials.']],
            ]);
    }

    public function test_login_without_credentials_returns_422(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email' => 'The email field is required.',
                'password' => 'The password field is required.',
            ]);
    }

    public function test_login_with_non_string_email_returns_422_not_500(): void
    {
        $this->postJson('/api/login', ['email' => ['alice@example.com'], 'password' => self::PASSWORD])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_login_returns_429_after_5_attempts_for_the_same_email_and_ip(): void
    {
        $user = User::factory()->create(['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertUnprocessable();
        }

        // Blocked even with the correct password, and regardless of email case.
        $this->postJson('/api/login', ['email' => 'ALICE@example.com', 'password' => self::PASSWORD])
            ->assertTooManyRequests();

        // Other emails from the same IP, and the same email from another IP, have their own counters.
        $this->postJson('/api/login', ['email' => 'bob@example.com', 'password' => 'wrong-password'])
            ->assertUnprocessable();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk();
    }
}

<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Wallet>
 */
class WalletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'balance' => 0,
            'currency' => 'BDT',
            'is_frozen' => false,
        ];
    }

    /**
     * Balance in paisa.
     */
    public function withBalance(int $paisa): static
    {
        return $this->state(fn () => ['balance' => $paisa]);
    }

    public function frozen(): static
    {
        return $this->state(fn () => ['is_frozen' => true]);
    }
}

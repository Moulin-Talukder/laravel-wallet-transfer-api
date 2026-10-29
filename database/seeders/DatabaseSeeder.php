<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed two demo users with funded wallets (balances in paisa).
     */
    public function run(): void
    {
        foreach (['alice@example.com' => 1_000_000, 'bob@example.com' => 250_000] as $email => $balance) {
            Wallet::factory()
                ->withBalance($balance)
                ->for(User::factory()->state(['email' => $email, 'password' => 'password']))
                ->create();
        }
    }
}

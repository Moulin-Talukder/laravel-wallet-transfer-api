<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Wallet;

class WalletPolicy
{
    public function view(User $user, Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id;
    }

    public function transferFrom(User $user, Wallet $wallet): bool
    {
        return $user->id === $wallet->user_id && ! $wallet->is_frozen;
    }
}

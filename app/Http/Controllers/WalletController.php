<?php

namespace App\Http\Controllers;

use App\Http\Resources\WalletResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WalletController extends Controller
{
    public function show(Request $request): WalletResource
    {
        $wallet = $request->user()->wallet()->firstOrFail();

        Gate::authorize('view', $wallet);

        return new WalletResource($wallet);
    }
}

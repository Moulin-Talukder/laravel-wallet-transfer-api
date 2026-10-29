<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Exchange an email and password for a Sanctum API token.
     *
     * Every failure returns the same message, and the web guard's timebox makes every
     * failure take the same minimum time, so neither reveals whether the email exists.
     *
     * @throws ValidationException
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $guard = Auth::guard('web');

        if (! $guard->validate($request->validated())) {
            throw ValidationException::withMessages(['email' => 'Invalid credentials.']);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();
        $lifetimeInMinutes = config('sanctum.expiration');
        $expiresAt = $lifetimeInMinutes ? now()->addMinutes($lifetimeInMinutes) : null;

        return response()->json([
            'token' => $user->createToken('frontend', expiresAt: $expiresAt)->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt?->toIso8601String(),
        ]);
    }

    /**
     * Revoke the token used for this request. Other tokens of the user stay valid.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }
}

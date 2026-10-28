<?php

namespace App\Http\Controllers;

use App\Exceptions\TransferException;
use App\Http\Requests\StoreTransferRequest;
use App\Http\Resources\TransferResource;
use App\Models\Transfer;
use App\Models\Wallet;
use App\Services\TransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class TransferController extends Controller
{
    public function __construct(private readonly TransferService $transfers)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $wallet = $request->user()->wallet()->firstOrFail();

        Gate::authorize('view', $wallet);

        $transfers = Transfer::query()
            ->where(fn ($query) => $query
                ->where('sender_wallet_id', $wallet->id)
                ->orWhere('receiver_wallet_id', $wallet->id))
            ->with('sender')
            ->latest()
            ->latest('id')
            ->paginate(20);

        return TransferResource::collection($transfers);
    }

    public function store(StoreTransferRequest $request): JsonResponse
    {
        $sender = $request->user()->wallet;

        // Same response whether the receiver has no wallet or a frozen one.
        $receiver = Wallet::query()
            ->whereRelation('user', 'email', $request->validated('receiver_email'))
            ->first() ?? throw TransferException::receiverUnavailable();

        [$transfer, $replayed] = $this->transfers->transfer(
            $sender,
            $receiver,
            (int) $request->validated('amount'),
            $request->validated('idempotency_key'),
        );

        $response = TransferResource::make($transfer)
            ->response()
            ->setStatusCode($replayed ? 200 : 201);

        return $replayed ? $response->header('Idempotent-Replayed', 'true') : $response;
    }
}

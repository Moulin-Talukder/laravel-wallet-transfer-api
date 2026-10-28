<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public view of a transfer, from the perspective of the authenticated user.
 * Internal ids and the idempotency key are intentionally never exposed.
 *
 * @mixin \App\Models\Transfer
 */
class TransferResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'amount' => $this->amount,
            'currency' => $this->sender->currency,
            'status' => $this->status,
            'direction' => $this->sender_wallet_id === $request->user()->wallet?->id ? 'out' : 'in',
            'created_at' => $this->created_at,
        ];
    }
}

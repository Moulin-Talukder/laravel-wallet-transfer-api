<?php

namespace App\Models;

use App\Enums\TransferStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sender_wallet_id', 'receiver_wallet_id', 'amount', 'idempotency_key', 'status'])]
#[Hidden(['id', 'sender_wallet_id', 'receiver_wallet_id', 'idempotency_key'])]
class Transfer extends Model
{
    use HasUuids;

    /**
     * Only the public reference is a UUID; the primary key stays an auto-increment integer.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['reference'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sender_wallet_id' => 'integer',
            'receiver_wallet_id' => 'integer',
            'amount' => 'integer',
            'status' => TransferStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'sender_wallet_id');
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'receiver_wallet_id');
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}

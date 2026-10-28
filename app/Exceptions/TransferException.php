<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An expected business-rule rejection. The code is a stable, machine-readable
 * contract for API clients; the message is for humans and may change.
 */
class TransferException extends RuntimeException
{
    public const INSUFFICIENT_BALANCE = 'INSUFFICIENT_BALANCE';

    public const SAME_WALLET = 'SAME_WALLET';

    public const RECEIVER_UNAVAILABLE = 'RECEIVER_UNAVAILABLE';

    public const SENDER_FROZEN = 'SENDER_FROZEN';

    public const IDEMPOTENCY_KEY_REUSED = 'IDEMPOTENCY_KEY_REUSED';

    private function __construct(string $message, public readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function insufficientBalance(): self
    {
        return new self('Insufficient balance.', self::INSUFFICIENT_BALANCE);
    }

    public static function sameWallet(): self
    {
        return new self('You cannot transfer money to your own wallet.', self::SAME_WALLET);
    }

    public static function receiverUnavailable(): self
    {
        return new self('The receiver cannot accept transfers right now.', self::RECEIVER_UNAVAILABLE);
    }

    public static function senderFrozen(): self
    {
        return new self('Your wallet is frozen.', self::SENDER_FROZEN);
    }

    public static function idempotencyKeyReused(): self
    {
        return new self('This Idempotency-Key was already used for a different transfer.', self::IDEMPOTENCY_KEY_REUSED);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], 422);
    }
}

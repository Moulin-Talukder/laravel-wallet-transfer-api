<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            // Public identifier exposed by the API; the auto-increment id stays internal.
            $table->uuid('reference')->unique();
            $table->foreignId('sender_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('receiver_wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->uuid('idempotency_key');
            $table->string('status', 20);
            $table->timestamps();

            // A key is scoped to its sender, so two users can never collide on the same key.
            $table->unique(['sender_wallet_id', 'idempotency_key']);
            $table->index(['sender_wallet_id', 'created_at']);
            $table->index(['receiver_wallet_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};

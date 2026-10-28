<?php

use App\Http\Controllers\TransferController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/wallet', [WalletController::class, 'show']);

    Route::get('/transfers', [TransferController::class, 'index']);
    Route::post('/transfers', [TransferController::class, 'store'])->middleware('throttle:10,1');
});

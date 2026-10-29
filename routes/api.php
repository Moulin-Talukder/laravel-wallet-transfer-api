<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('throttle:wallet-reads')->group(function () {
        Route::get('/wallet', [WalletController::class, 'show']);
        Route::get('/transfers', [TransferController::class, 'index']);
    });

    Route::post('/transfers', [TransferController::class, 'store'])->middleware('throttle:transfers');
});

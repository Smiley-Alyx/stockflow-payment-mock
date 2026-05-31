<?php

use App\Http\Controllers\DebugController;
use App\Http\Controllers\PaymentAttemptController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SandboxCardController;
use Illuminate\Support\Facades\Route;

Route::prefix('sandbox')->group(function (): void {
    Route::get('/cards', [SandboxCardController::class, 'index']);
    Route::post('/tokens', [SandboxCardController::class, 'tokenize']);
});

Route::get('/payments', [PaymentController::class, 'index']);
Route::get('/payments/{paymentId}', [PaymentController::class, 'show']);

Route::get('/payment-attempts', [PaymentAttemptController::class, 'index']);
Route::get('/payment-attempts/{attemptId}', [PaymentAttemptController::class, 'show']);

Route::prefix('debug')->group(function (): void {
    Route::get('/failure-mode', [DebugController::class, 'showFailureMode']);
    Route::post('/failure-mode', [DebugController::class, 'setFailureMode']);
    Route::post('/reset', [DebugController::class, 'reset']);
    Route::get('/dlq', [DebugController::class, 'showDlq']);
    Route::post('/dlq/requeue', [DebugController::class, 'requeueDlq']);
});

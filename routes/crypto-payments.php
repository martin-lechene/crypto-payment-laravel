<?php

use Illuminate\Support\Facades\Route;
use MartinLechene\CryptoPayments\Http\Controllers\{
    PaymentController,
    AddressController,
    WebhookController,
};

Route::prefix('api/crypto-payments')->group(function () {
    // Paiements
    Route::post('/payments', [PaymentController::class, 'create'])
        ->name('crypto-payments.create');
    
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])
        ->name('crypto-payments.show');
    
    Route::get('/payments/{payment}/status', [PaymentController::class, 'checkStatus'])
        ->name('crypto-payments.check-status');
    
    Route::get('/payments', [PaymentController::class, 'list'])
        ->name('crypto-payments.list');
    
    Route::post('/payments/{payment}/refund', [PaymentController::class, 'refund'])
        ->name('crypto-payments.refund');
    
    // Adresses
    Route::get('/addresses', [AddressController::class, 'list'])
        ->name('crypto-payments.addresses.list');
    
    Route::get('/addresses/{address}', [AddressController::class, 'show'])
        ->name('crypto-payments.addresses.show');
    
    Route::patch('/addresses/{address}/label', [AddressController::class, 'updateLabel'])
        ->name('crypto-payments.addresses.update-label');
    
    Route::post('/addresses/{address}/deactivate', [AddressController::class, 'deactivate'])
        ->name('crypto-payments.addresses.deactivate');
    
    // Webhooks
    Route::post('/webhooks', [WebhookController::class, 'create'])
        ->name('crypto-payments.webhooks.create');
    
    Route::get('/webhooks', [WebhookController::class, 'list'])
        ->name('crypto-payments.webhooks.list');
    
    Route::patch('/webhooks/{endpoint}', [WebhookController::class, 'update'])
        ->name('crypto-payments.webhooks.update');
    
    Route::delete('/webhooks/{endpoint}', [WebhookController::class, 'delete'])
        ->name('crypto-payments.webhooks.delete');
    
    Route::post('/webhooks/{endpoint}/test', [WebhookController::class, 'testEvent'])
        ->name('crypto-payments.webhooks.test');
});


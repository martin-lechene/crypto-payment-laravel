<?php

namespace MartinLechene\CryptoPayments\Jobs;

use MartinLechene\CryptoPayments\Models\CryptoPayment;
use MartinLechene\CryptoPayments\Services\PaymentManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckPaymentConfirmations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 600;
    public $tries = 3;
    
    public function handle(PaymentManager $paymentManager): void
    {
        CryptoPayment::whereIn('status', [
            CryptoPayment::STATUS_PENDING,
            CryptoPayment::STATUS_CONFIRMING,
        ])->whereNull('deleted_at')
        ->where('expires_at', '>', now())
        ->chunk(100, function ($payments) use ($paymentManager) {
            foreach ($payments as $payment) {
                try {
                    $paymentManager->checkPaymentStatus($payment);
                } catch (\Exception $e) {
                    Log::error('Erreur vérification paiement ' . $payment->id . ': ' . $e->getMessage());
                }
            }
        });
    }
}


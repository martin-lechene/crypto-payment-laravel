<?php

namespace CreabyIA\CryptoPayments\Services;

use CreabyIA\CryptoPayments\Models\CryptoPayment;
use CreabyIA\CryptoPayments\Models\WebhookEvent;
use CreabyIA\CryptoPayments\Models\PaymentWebhookEndpoint;
use CreabyIA\CryptoPayments\Jobs\SendWebhookEvent;
use Illuminate\Support\Facades\Queue;

class WebhookManager
{
    public function trigger(CryptoPayment $payment, string $eventType): void
    {
        if (!config('crypto-payments.webhooks.enabled')) {
            return;
        }
        
        // Récupérer les endpoints actifs
        $endpoints = PaymentWebhookEndpoint::active()
            ->where('merchant_id', $payment->merchant_id)
            ->get();
        
        foreach ($endpoints as $endpoint) {
            if (!$endpoint->supportsEvent($eventType)) {
                continue;
            }
            
            // Créer l'événement webhook
            $event = WebhookEvent::create([
                'payment_id' => $payment->id,
                'event_type' => $eventType,
                'webhook_url' => $endpoint->url,
                'payload' => $this->buildPayload($payment, $eventType),
                'attempt' => 1,
                'max_attempts' => config('crypto-payments.webhooks.retry_attempts', 3),
            ]);
            
            // Dispatcher le job
            Queue::push(new SendWebhookEvent($event, $endpoint));
        }
    }
    
    protected function buildPayload(CryptoPayment $payment, string $eventType): array
    {
        return [
            'event' => $eventType,
            'timestamp' => now()->toIso8601String(),
            'payment' => [
                'id' => $payment->id,
                'reference_code' => $payment->reference_code,
                'currency' => $payment->currency,
                'amount_crypto' => (string) $payment->amount_crypto,
                'amount_fiat' => (string) $payment->amount_fiat,
                'fiat_currency' => $payment->fiat_currency,
                'wallet_address' => $payment->wallet_address,
                'transaction_hash' => $payment->transaction_hash,
                'status' => $payment->status,
                'confirmations' => $payment->confirmations,
                'required_confirmations' => $payment->required_confirmations,
                'exchange_rate' => (string) $payment->exchange_rate,
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'metadata' => $payment->metadata,
            ],
        ];
    }
}


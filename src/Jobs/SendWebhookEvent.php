<?php

namespace MartinLechene\CryptoPayments\Jobs;

use MartinLechene\CryptoPayments\Models\WebhookEvent;
use MartinLechene\CryptoPayments\Models\PaymentWebhookEndpoint;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public $timeout = 60;
    
    public function __construct(
        protected WebhookEvent $event,
        protected PaymentWebhookEndpoint $endpoint
    ) {}
    
    public function handle(): void
    {
        try {
            $payload = $this->event->payload;
            $signature = $this->generateSignature($payload);
            
            $response = Http::timeout(config('crypto-payments.webhooks.timeout', 30))
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Event' => $this->event->event_type,
                ])
                ->post($this->event->webhook_url, $payload);
            
            if ($response->successful()) {
                $this->event->update([
                    'response_code' => $response->status(),
                    'response_body' => $response->json(),
                    'completed_at' => now(),
                ]);
                
                // Reset consecutive failures
                $this->endpoint->update(['consecutive_failures' => 0]);
            } else {
                $this->retry($response);
            }
        } catch (\Exception $e) {
            $this->retry(null, $e);
        }
    }
    
    protected function retry($response = null, $exception = null): void
    {
        if ($this->event->attempt >= $this->event->max_attempts) {
            $this->event->update([
                'failed_at' => now(),
                'response_code' => $response?->status() ?? 0,
                'response_body' => ['error' => $exception?->getMessage()],
            ]);
            
            $this->endpoint->increment('consecutive_failures');
            
            if ($this->endpoint->consecutive_failures >= 5) {
                $this->endpoint->update(['is_active' => false]);
                Log::warning("Endpoint webhook désactivé: " . $this->endpoint->url);
            }
            
            return;
        }
        
        $nextRetry = now()->addSeconds(
            config('crypto-payments.webhooks.retry_delay', 60) * pow(2, $this->event->attempt - 1)
        );
        
        $this->event->update([
            'attempt' => $this->event->attempt + 1,
            'next_retry_at' => $nextRetry,
            'response_code' => $response?->status(),
        ]);
        
        $this->release($nextRetry);
    }
    
    protected function generateSignature(array $payload): string
    {
        $secret = $this->endpoint->secret;
        $message = json_encode($payload);
        return 'sha256=' . hash_hmac('sha256', $message, $secret);
    }
}


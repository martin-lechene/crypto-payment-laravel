<?php

namespace MartinLechene\CryptoPayments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MartinLechene\CryptoPayments\Models\PaymentWebhookEndpoint;
use MartinLechene\CryptoPayments\Models\WebhookEvent;

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
            $timestamp = time();
            $signature = $this->generateSignature($payload, $timestamp);

            $response = Http::timeout(config('crypto-payments.webhooks.timeout', 30))
                ->asJson()
                ->withHeaders([
                    'X-Webhook-Signature' => $signature,
                    'X-Webhook-Timestamp' => (string) $timestamp,
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
                $this->endpoint->update([
                    'consecutive_failures' => 0,
                    'last_triggered_at' => now(),
                ]);
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
                Log::warning('Endpoint webhook désactivé: '.$this->endpoint->url);
            }

            return;
        }

        $backoffSeconds = (int) config('crypto-payments.webhooks.retry_delay', 60)
            * pow(2, $this->event->attempt - 1);

        $this->event->update([
            'attempt' => $this->event->attempt + 1,
            'next_retry_at' => now()->addSeconds($backoffSeconds),
            'response_code' => $response?->status(),
        ]);

        $this->release($backoffSeconds);
    }

    protected function generateSignature(array $payload, int $timestamp): string
    {
        $secret = $this->endpoint->secret;
        $body = $this->encodeBody($payload);

        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    protected function encodeBody(array $payload): string
    {
        return json_encode($payload);
    }
}

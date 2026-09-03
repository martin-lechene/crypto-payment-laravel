<?php

namespace MartinLechene\CryptoPayments\Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Jobs\SendWebhookEvent;
use MartinLechene\CryptoPayments\Models\CryptoPayment;
use MartinLechene\CryptoPayments\Models\PaymentWebhookEndpoint;
use MartinLechene\CryptoPayments\Models\WebhookEvent;
use MartinLechene\CryptoPayments\Tests\TestCase;

const WH_URL = 'https://merchant.example/hooks';
function whEndpoint(array $attributes = []): PaymentWebhookEndpoint
{
    return PaymentWebhookEndpoint::create(array_merge([
        'merchant_id' => 1,
        'url' => WH_URL,
        'events' => ['payment_created', 'payment_confirmed'],
        'secret' => str_repeat('s', 64),
        'is_active' => true,
    ], $attributes));
}
function whEvent(PaymentWebhookEndpoint $endpoint, array $attributes = []): WebhookEvent
{
    return WebhookEvent::create(array_merge([
        'payment_id' => null,
        'event_type' => 'payment_created',
        'webhook_url' => $endpoint->url,
        'payload' => ['event' => 'payment_created', 'payment' => ['id' => 1], 'reference' => 'x'],
        'attempt' => 1,
        'max_attempts' => 3,
    ], $attributes));
}

class WebhookDeliveryTest extends TestCase
{
    public function test_signe_le_webhook_avec_timestamp_et_marque_l_evenement_livre(): void
    {
            $endpoint = whEndpoint();
            $event = whEvent($endpoint);

            $captured = null;

            Http::fake([
                'merchant.example/*' => function (Request $request) use (&$captured) {
                    $captured = $request;

                    return Http::response(['ok' => true], 200);
                },
            ]);

            (new SendWebhookEvent($event, $endpoint))->handle();

        $this->assertNotNull($captured);
        $this->assertTrue($captured->hasHeader('X-Webhook-Signature'));
        $this->assertTrue($captured->hasHeader('X-Webhook-Timestamp'));
        $this->assertTrue($captured->hasHeader('X-Webhook-Event'));
        $this->assertSame('payment_created', $captured->header('X-Webhook-Event')[0]);
        $this->assertStringContainsString('application/json', $captured->header('Content-Type')[0]);

            $timestamp = (int) $captured->header('X-Webhook-Timestamp')[0];
            $body = $captured->body();
            $expected = 'sha256='.hash_hmac(
                'sha256',
                $timestamp.'.'.$body,
                $endpoint->secret
            );

        $this->assertSame($expected, $captured->header('X-Webhook-Signature')[0]);

            $event->refresh();
        $this->assertNotNull($event->completed_at);
        $this->assertSame(200, $event->response_code);

            $endpoint->refresh();
        $this->assertSame(0, $endpoint->consecutive_failures);
        $this->assertNotNull($endpoint->last_triggered_at);
    }

    public function test_planifie_la_retry_en_cas_d_echec_http(): void
    {
            $endpoint = whEndpoint();
            $event = whEvent($endpoint);

            Http::fake([
                'merchant.example/*' => Http::response([], 500),
            ]);

            (new SendWebhookEvent($event, $endpoint))->handle();

            $event->refresh();

        $this->assertSame(2, $event->attempt);
        $this->assertNull($event->completed_at);
        $this->assertNull($event->failed_at);
        $this->assertNotNull($event->next_retry_at);
        $this->assertTrue($event->next_retry_at->isFuture());
    }

    public function test_marque_comme_echoue_apres_epuisement_des_tentatives(): void
    {
            $endpoint = whEndpoint();
            $event = whEvent($endpoint, ['max_attempts' => 2]);

            Http::fake([
                'merchant.example/*' => Http::response([], 500),
            ]);

            (new SendWebhookEvent($event, $endpoint))->handle();

            $event->refresh();
        $this->assertSame(2, $event->attempt);

            (new SendWebhookEvent($event, $endpoint))->handle();

            $event->refresh();
        $this->assertSame(2, $event->attempt);
        $this->assertNotNull($event->failed_at);

            $endpoint->refresh();
        $this->assertSame(1, $endpoint->consecutive_failures);
    }

    public function test_desactive_l_endpoint_apres_cinq_echecs_consecutifs(): void
    {
            $endpoint = whEndpoint(['consecutive_failures' => 4]);
            $event = whEvent($endpoint, ['max_attempts' => 1]);

            Http::fake([
                'merchant.example/*' => Http::response([], 500),
            ]);

            (new SendWebhookEvent($event, $endpoint))->handle();

            $endpoint->refresh();

        $this->assertSame(5, $endpoint->consecutive_failures);
        $this->assertFalse($endpoint->is_active);
    }

    public function test_enregistre_la_livraison_sur_un_paiement_reel(): void
    {
            $payment = CryptoPayment::create([
                'order_id' => 1,
                'currency' => 'BTC',
                'amount_crypto' => 0.001,
                'amount_fiat' => 60,
                'fiat_currency' => 'USD',
                'wallet_address' => 'tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r',
                'status' => CryptoPayment::STATUS_COMPLETED,
                'confirmations' => 3,
                'required_confirmations' => 3,
                'exchange_rate' => 60000,
                'platform_fee' => 0,
                'fee_percentage' => 0,
                'reference_code' => 'T-12345678',
            ]);

            $endpoint = whEndpoint();
            $event = WebhookEvent::create([
                'payment_id' => $payment->id,
                'event_type' => 'payment_completed',
                'webhook_url' => $endpoint->url,
                'payload' => ['event' => 'payment_completed', 'payment' => ['id' => $payment->id]],
                'attempt' => 1,
                'max_attempts' => 3,
            ]);

            Http::fake([
                'merchant.example/*' => Http::response(['ok' => true], 200),
            ]);

            (new SendWebhookEvent($event, $endpoint))->handle();

            $event->refresh();

        $this->assertNotNull($event->completed_at);
    }
}

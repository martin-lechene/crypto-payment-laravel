<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Jobs\SendWebhookEvent;
use MartinLechene\CryptoPayments\Models\CryptoPayment;
use MartinLechene\CryptoPayments\Models\PaymentWebhookEndpoint;
use MartinLechene\CryptoPayments\Models\WebhookEvent;

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

it('signe le webhook avec timestamp et marque l événement livré', function () {
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

    expect($captured)->not->toBeNull();
    expect($captured->hasHeader('X-Webhook-Signature'))->toBeTrue();
    expect($captured->hasHeader('X-Webhook-Timestamp'))->toBeTrue();
    expect($captured->hasHeader('X-Webhook-Event'))->toBeTrue();
    expect($captured->header('X-Webhook-Event')[0])->toBe('payment_created');
    expect($captured->header('Content-Type')[0])->toContain('application/json');

    $timestamp = (int) $captured->header('X-Webhook-Timestamp')[0];
    $body = $captured->body();
    $expected = 'sha256='.hash_hmac(
        'sha256',
        $timestamp.'.'.$body,
        $endpoint->secret
    );

    expect($captured->header('X-Webhook-Signature')[0])->toBe($expected);

    $event->refresh();
    expect($event->completed_at)->not->toBeNull();
    expect($event->response_code)->toBe(200);

    $endpoint->refresh();
    expect($endpoint->consecutive_failures)->toBe(0);
    expect($endpoint->last_triggered_at)->not->toBeNull();
});

it('planifie la retry en cas d échec HTTP', function () {
    $endpoint = whEndpoint();
    $event = whEvent($endpoint);

    Http::fake([
        'merchant.example/*' => Http::response([], 500),
    ]);

    (new SendWebhookEvent($event, $endpoint))->handle();

    $event->refresh();

    expect($event->attempt)->toBe(2);
    expect($event->completed_at)->toBeNull();
    expect($event->failed_at)->toBeNull();
    expect($event->next_retry_at)->not->toBeNull();
    expect($event->next_retry_at->isFuture())->toBeTrue();
});

it('marque comme échoué après épuisement des tentatives', function () {
    $endpoint = whEndpoint();
    $event = whEvent($endpoint, ['max_attempts' => 2]);

    Http::fake([
        'merchant.example/*' => Http::response([], 500),
    ]);

    (new SendWebhookEvent($event, $endpoint))->handle();

    $event->refresh();
    expect($event->attempt)->toBe(2);

    (new SendWebhookEvent($event, $endpoint))->handle();

    $event->refresh();
    expect($event->attempt)->toBe(2);
    expect($event->failed_at)->not->toBeNull();

    $endpoint->refresh();
    expect($endpoint->consecutive_failures)->toBe(1);
});

it('désactive l endpoint après cinq échecs consécutifs', function () {
    $endpoint = whEndpoint(['consecutive_failures' => 4]);
    $event = whEvent($endpoint, ['max_attempts' => 1]);

    Http::fake([
        'merchant.example/*' => Http::response([], 500),
    ]);

    (new SendWebhookEvent($event, $endpoint))->handle();

    $endpoint->refresh();

    expect($endpoint->consecutive_failures)->toBe(5);
    expect($endpoint->is_active)->toBeFalse();
});

it('enregistre la livraison sur un paiement réel', function () {
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

    expect($event->completed_at)->not->toBeNull();
});

<?php

use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Models\PaymentWebhookEndpoint;
use MartinLechene\CryptoPayments\Models\WebhookEvent;

it('crée, liste, met à jour, teste et supprime un endpoint webhook via l API', function () {
    Http::fake([
        'merchant.example/*' => Http::response(['ok' => true], 200),
    ]);

    $response = $this->postJson('/api/crypto-payments/webhooks', [
        'merchant_id' => 5,
        'url' => 'https://merchant.example/hook',
        'events' => ['payment_confirmed', 'payment_completed'],
        'description' => 'Boutique principale',
    ]);

    $response->assertStatus(201)->assertJsonPath('success', true);

    $data = $response->json('data');
    expect($data['url'])->toBe('https://merchant.example/hook');
    expect($data['events'])->toContain('payment_confirmed');
    expect($data['is_active'])->toBeTrue();
    expect($data['secret'])->not->toBeNull();

    $id = $data['id'];
    expect(PaymentWebhookEndpoint::find($id))->not->toBeNull();

    $this->getJson('/api/crypto-payments/webhooks?merchant_id=5')
        ->assertOk()
        ->assertJsonCount(1, 'data.data');

    $this->patchJson("/api/crypto-payments/webhooks/{$id}", [
        'url' => 'https://merchant.example/hook-v2',
        'is_active' => false,
    ])->assertOk();

    $endpoint = PaymentWebhookEndpoint::find($id);
    expect($endpoint->url)->toBe('https://merchant.example/hook-v2');
    expect($endpoint->is_active)->toBeFalse();

    $this->postJson("/api/crypto-payments/webhooks/{$id}/test")->assertOk();

    expect(WebhookEvent::where('event_type', 'test')->count())->toBe(1);

    $this->deleteJson("/api/crypto-payments/webhooks/{$id}")->assertOk();
    expect(PaymentWebhookEndpoint::find($id))->toBeNull();
});

it('valide les données de création de webhook', function () {
    $this->postJson('/api/crypto-payments/webhooks', [
        'merchant_id' => 1,
        'url' => 'pas-une-url',
        'events' => ['evenement_inconnu'],
    ])->assertStatus(422);
});

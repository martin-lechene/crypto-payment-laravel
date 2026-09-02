<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Models\CryptoPayment;

const API_ADDRESS = 'tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r';

function apiFakes(): void
{
    Http::fake([
        'api.coingecko.com/*' => fn () => Http::response(['bitcoin' => ['usd' => 60000, 'eur' => 55000]]),
        'localhost:18332*' => function (Request $request) {
            $method = json_decode($request->body(), true)['method'] ?? '';

            $result = match ($method) {
                'getnewaddress' => API_ADDRESS,
                'getrawtransaction' => [
                    'txid' => str_repeat('ab', 32),
                    'confirmations' => 6,
                    'blockheight' => 1_000_000,
                    'blockhash' => str_repeat('aa', 32),
                    'vin' => [['txid' => str_repeat('dd', 32)]],
                    'vout' => [
                        ['scriptPubKey' => ['addresses' => [API_ADDRESS]], 'value' => 0.0006],
                    ],
                ],
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => uniqid(), 'result' => $result]);
        },
        'blockstream.info/*' => Http::response([]),
    ]);
}

it('crée puis consulte un paiement via l API', function () {
    apiFakes();

    $response = $this->postJson('/api/crypto-payments/payments', [
        'currency' => 'BTC',
        'amount' => 30,
        'fiat_currency' => 'USD',
        'order_id' => 10,
        'merchant_id' => 3,
    ]);

    $response->assertStatus(201)->assertJsonPath('success', true);
    $response->assertJsonPath('data.currency', 'BTC');
    $response->assertJsonPath('data.status', 'pending');
    $response->assertJsonPath('data.wallet_address', API_ADDRESS);

    $id = $response->json('data.id');

    expect(CryptoPayment::find($id))->not->toBeNull();

    $this->getJson("/api/crypto-payments/payments/{$id}")
        ->assertOk()
        ->assertJsonPath('data.id', $id);
});

it('liste et filtre les paiements', function () {
    apiFakes();

    $this->postJson('/api/crypto-payments/payments', [
        'currency' => 'BTC', 'amount' => 30, 'fiat_currency' => 'USD', 'merchant_id' => 3,
    ])->assertStatus(201);
    $this->postJson('/api/crypto-payments/payments', [
        'currency' => 'BTC', 'amount' => 50, 'fiat_currency' => 'EUR', 'merchant_id' => 9,
    ])->assertStatus(201);

    $this->getJson('/api/crypto-payments/payments')
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('rejette une devise invalide avec une erreur 422', function () {
    apiFakes();

    $this->postJson('/api/crypto-payments/payments', [
        'currency' => 'DOGE',
        'amount' => 10,
        'fiat_currency' => 'USD',
    ])->assertStatus(422);
});

it('vérifie le statut et rembourse un paiement', function () {
    apiFakes();

    $payment = CryptoPayment::create([
        'currency' => 'BTC',
        'amount_crypto' => 0.0005,
        'amount_fiat' => 30,
        'fiat_currency' => 'USD',
        'wallet_address' => API_ADDRESS,
        'status' => CryptoPayment::STATUS_PENDING,
        'confirmations' => 0,
        'required_confirmations' => 3,
        'exchange_rate' => 60000,
        'platform_fee' => 0,
        'fee_percentage' => 0,
        'reference_code' => 'B-0001',
    ]);

    $this->getJson("/api/crypto-payments/payments/{$payment->id}/status")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    $this->postJson("/api/crypto-payments/payments/{$payment->id}/refund", [
        'reason' => 'commande annulée',
    ])
        ->assertOk()
        ->assertJsonPath('success', true);

    expect($payment->refresh()->status)->toBe(CryptoPayment::STATUS_REFUNDED);
});

<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\PaymentException;
use MartinLechene\CryptoPayments\Models\BlockchainTransaction;
use MartinLechene\CryptoPayments\Models\CryptoAddress;
use MartinLechene\CryptoPayments\Models\CryptoPayment;
use MartinLechene\CryptoPayments\Models\ExchangeRate;
use MartinLechene\CryptoPayments\Services\PaymentManager;

const PM_ADDRESS = 'tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r';
const PM_TX = 'abababababababababababababababababababababababababababababababab';

function pmBitcoinRpc(): Closure
{
    return function (Request $request) {
        $method = json_decode($request->body(), true)['method'] ?? '';

        $result = match ($method) {
            'getnewaddress' => PM_ADDRESS,
            'getrawtransaction' => [
                'txid' => PM_TX,
                'confirmations' => 6,
                'blockhash' => str_repeat('aa', 32),
                'blockheight' => 1000000,
                'vin' => [['txid' => str_repeat('dd', 32)]],
                'vout' => [
                    ['scriptPubKey' => ['addresses' => [PM_ADDRESS]], 'value' => 0.0006],
                ],
            ],
            default => null,
        };

        return Http::response(['jsonrpc' => '2.0', 'id' => uniqid(), 'result' => $result]);
    };
}

function pmCoingecko(): Closure
{
    return fn () => Http::response(['bitcoin' => ['usd' => 60000], 'ethereum' => ['usd' => 3000]]);
}

function pmExplorerWithPayment(): Closure
{
    return fn () => Http::response([
        [
            'txid' => PM_TX,
            'vout' => [
                ['scriptpubkey_address' => PM_ADDRESS, 'value' => 60000],
            ],
        ],
    ]);
}

beforeEach(function () {
    $this->pm = $this->app->make(PaymentManager::class);
});

it('crée une demande de paiement avec adresse et taux', function () {
    Http::fake([
        'api.coingecko.com/*' => pmCoingecko(),
        'localhost:18332*' => pmBitcoinRpc(),
    ]);

    $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD', [
        'merchant_id' => 7,
        'order_id' => 42,
        'description' => 'Commande test',
    ]);

    expect($payment->currency)->toBe('BTC');
    expect((float) $payment->amount_crypto)->toBe(0.0005);
    expect($payment->wallet_address)->toBe(PM_ADDRESS);
    expect($payment->status)->toBe(CryptoPayment::STATUS_PENDING);
    expect($payment->merchant_id)->toBe(7);
    expect($payment->order_id)->toBe(42);
    expect($payment->required_confirmations)->toBe(3);
    expect($payment->reference_code)->not->toBeNull()->not->toBeEmpty();

    expect(CryptoPayment::count())->toBe(1);
    expect(CryptoAddress::where('address', PM_ADDRESS)->exists())->toBeTrue();
    expect(ExchangeRate::for('BTC', 'USD')->exists())->toBeTrue();
});

it('reporte la détection et la confirmation d une transaction', function () {
    Http::fake([
        'api.coingecko.com/*' => pmCoingecko(),
        'localhost:18332*' => pmBitcoinRpc(),
        'blockstream.info/*' => pmExplorerWithPayment(),
    ]);

    $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

    $this->pm->checkPaymentStatus($payment->refresh());

    $payment->refresh();

    expect($payment->transaction_hash)->toBe(PM_TX);
    expect($payment->confirmations)->toBe(6);
    expect($payment->status)->toBe(CryptoPayment::STATUS_CONFIRMED);

    expect(BlockchainTransaction::where('tx_hash', PM_TX)->exists())->toBeTrue();

    $record = BlockchainTransaction::where('tx_hash', PM_TX)->first();
    expect($record->payment_id)->toBe($payment->id);
    expect(strtolower($record->to_address))->toBe(PM_ADDRESS);
});

it('ne crée pas de doublon de transaction enregistrée', function () {
    Http::fake([
        'api.coingecko.com/*' => pmCoingecko(),
        'localhost:18332*' => pmBitcoinRpc(),
        'blockstream.info/*' => pmExplorerWithPayment(),
    ]);

    $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

    $this->pm->checkPaymentStatus($payment->refresh());
    $this->pm->checkPaymentStatus($payment->refresh());

    expect(BlockchainTransaction::where('tx_hash', PM_TX)->count())->toBe(1);
});

it('reste en attente tant qu aucune transaction n est détectée', function () {
    Http::fake([
        'api.coingecko.com/*' => pmCoingecko(),
        'localhost:18332*' => pmBitcoinRpc(),
        'blockstream.info/*' => Http::response([]),
    ]);

    $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

    $this->pm->checkPaymentStatus($payment->refresh());

    $payment->refresh();

    expect($payment->transaction_hash)->toBeNull();
    expect($payment->status)->toBe(CryptoPayment::STATUS_PENDING);
});

it('expire un paiement en attente après son échéance', function () {
    Http::fake([
        'api.coingecko.com/*' => pmCoingecko(),
        'localhost:18332*' => pmBitcoinRpc(),
        'blockstream.info/*' => Http::response([]),
    ]);

    $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

    $payment->update(['expires_at' => now()->subMinute()]);

    $this->pm->checkPaymentStatus($payment->refresh());

    expect($payment->refresh()->status)->toBe(CryptoPayment::STATUS_EXPIRED);

    $this->pm->checkPaymentStatus($payment->refresh());

    expect($payment->refresh()->status)->toBe(CryptoPayment::STATUS_EXPIRED);
});

it('rejette une devise non supportée', function () {
    Http::fake();

    $this->pm->createPaymentRequest('DOGE', 10, 'USD');
})->throws(PaymentException::class);

it('gère le flux Ethereum avec l explorateur', function () {
    $ethAddress = '0x'.str_repeat('be', 20);
    $ethTx = '0x'.str_repeat('ef', 32);

    Http::fake([
        'api.coingecko.com/*' => pmCoingecko(),
        'localhost:8545*' => function (Request $request) use ($ethAddress) {
            $body = json_decode($request->body(), true);
            $method = $body['method'] ?? '';

            $result = match ($method) {
                'personal_newAccount' => $ethAddress,
                'eth_getTransactionByHash' => [
                    'from' => '0x'.str_repeat('aa', 20),
                    'to' => $ethAddress,
                    'value' => '0x6f05b59d3b20000',
                    'blockNumber' => '0x10',
                ],
                'eth_getTransactionReceipt' => ['blockNumber' => '0x10'],
                'eth_blockNumber' => '0x1c',
                default => null,
            };

            return Http::response(['jsonrpc' => '2.0', 'id' => uniqid(), 'result' => $result]);
        },
        'api-sepolia.etherscan.io/*' => Http::response([
            'status' => '1',
            'message' => 'OK',
            'result' => [
                ['hash' => $ethTx, 'to' => $ethAddress, 'isError' => '0', 'value' => '500000000000000000'],
            ],
        ]),
    ]);

    $payment = $this->pm->createPaymentRequest('ETH', 150, 'USD');

    expect($payment->wallet_address)->toBe($ethAddress);
    expect((float) $payment->amount_crypto)->toBe(0.05);

    $this->pm->checkPaymentStatus($payment->refresh());

    $payment->refresh();

    expect($payment->transaction_hash)->toBe($ethTx);
    expect($payment->confirmations)->toBe(12);
    expect($payment->status)->toBe(CryptoPayment::STATUS_CONFIRMED);
});

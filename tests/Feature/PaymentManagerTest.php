<?php

namespace MartinLechene\CryptoPayments\Tests\Feature;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\PaymentException;
use MartinLechene\CryptoPayments\Models\BlockchainTransaction;
use MartinLechene\CryptoPayments\Models\CryptoAddress;
use MartinLechene\CryptoPayments\Models\CryptoPayment;
use MartinLechene\CryptoPayments\Models\ExchangeRate;
use MartinLechene\CryptoPayments\Services\PaymentManager;
use MartinLechene\CryptoPayments\Tests\TestCase;

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

class PaymentManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->pm = $this->app->make(PaymentManager::class);
    }

    public function test_cree_une_demande_de_paiement_avec_adresse_et_taux(): void
    {
        Http::fake([
            'api.coingecko.com/*' => pmCoingecko(),
            'localhost:18332*' => pmBitcoinRpc(),
        ]);

        $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD', [
            'merchant_id' => 7,
            'order_id' => 42,
            'description' => 'Commande test',
        ]);

        $this->assertSame('BTC', $payment->currency);
        $this->assertSame(0.0005, (float) $payment->amount_crypto);
        $this->assertSame(PM_ADDRESS, $payment->wallet_address);
        $this->assertSame(CryptoPayment::STATUS_PENDING, $payment->status);
        $this->assertSame(7, $payment->merchant_id);
        $this->assertSame(42, $payment->order_id);
        $this->assertSame(3, $payment->required_confirmations);
        $this->assertNotNull($payment->reference_code);
        $this->assertNotEmpty($payment->reference_code);

        $this->assertSame(1, CryptoPayment::count());
        $this->assertTrue(CryptoAddress::where('address', PM_ADDRESS)->exists());
        $this->assertTrue(ExchangeRate::for('BTC', 'USD')->exists());
    }

    public function test_reporte_la_detection_et_la_confirmation_d_une_transaction(): void
    {
        Http::fake([
            'api.coingecko.com/*' => pmCoingecko(),
            'localhost:18332*' => pmBitcoinRpc(),
            'blockstream.info/*' => pmExplorerWithPayment(),
        ]);

        $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

        $this->pm->checkPaymentStatus($payment->refresh());

        $payment->refresh();

        $this->assertSame(PM_TX, $payment->transaction_hash);
        $this->assertSame(6, $payment->confirmations);
        $this->assertSame(CryptoPayment::STATUS_CONFIRMED, $payment->status);

        $this->assertTrue(BlockchainTransaction::where('tx_hash', PM_TX)->exists());

        $record = BlockchainTransaction::where('tx_hash', PM_TX)->first();
        $this->assertSame($payment->id, $record->payment_id);
        $this->assertSame(PM_ADDRESS, strtolower($record->to_address));
    }

    public function test_ne_cree_pas_de_doublon_de_transaction_enregistree(): void
    {
        Http::fake([
            'api.coingecko.com/*' => pmCoingecko(),
            'localhost:18332*' => pmBitcoinRpc(),
            'blockstream.info/*' => pmExplorerWithPayment(),
        ]);

        $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

        $this->pm->checkPaymentStatus($payment->refresh());
        $this->pm->checkPaymentStatus($payment->refresh());

        $this->assertSame(1, BlockchainTransaction::where('tx_hash', PM_TX)->count());
    }

    public function test_reste_en_attente_tant_qu_aucune_transaction_n_est_detectee(): void
    {
        Http::fake([
            'api.coingecko.com/*' => pmCoingecko(),
            'localhost:18332*' => pmBitcoinRpc(),
            'blockstream.info/*' => Http::response([]),
        ]);

        $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

        $this->pm->checkPaymentStatus($payment->refresh());

        $payment->refresh();

        $this->assertNull($payment->transaction_hash);
        $this->assertSame(CryptoPayment::STATUS_PENDING, $payment->status);
    }

    public function test_expire_un_paiement_en_attente_apres_son_echeance(): void
    {
        Http::fake([
            'api.coingecko.com/*' => pmCoingecko(),
            'localhost:18332*' => pmBitcoinRpc(),
            'blockstream.info/*' => Http::response([]),
        ]);

        $payment = $this->pm->createPaymentRequest('BTC', 30, 'USD');

        $payment->update(['expires_at' => now()->subMinute()]);

        $this->pm->checkPaymentStatus($payment->refresh());

        $this->assertSame(CryptoPayment::STATUS_EXPIRED, $payment->refresh()->status);

        $this->pm->checkPaymentStatus($payment->refresh());

        $this->assertSame(CryptoPayment::STATUS_EXPIRED, $payment->refresh()->status);
    }

    public function test_rejette_une_devise_non_supportee(): void
    {
        $this->expectException(PaymentException::class);

        Http::fake();

        $this->pm->createPaymentRequest('DOGE', 10, 'USD');
    }

    public function test_gere_le_flux_ethereum_avec_l_explorateur(): void
    {
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

        $this->assertSame($ethAddress, $payment->wallet_address);
        $this->assertSame(0.05, (float) $payment->amount_crypto);

        $this->pm->checkPaymentStatus($payment->refresh());

        $payment->refresh();

        $this->assertSame($ethTx, $payment->transaction_hash);
        $this->assertSame(12, $payment->confirmations);
        $this->assertSame(CryptoPayment::STATUS_CONFIRMED, $payment->status);
    }
}

<?php

namespace MartinLechene\CryptoPayments\Tests\Unit;

use MartinLechene\CryptoPayments\Tests\PackageTestCase;

use MartinLechene\CryptoPayments\Services\BitcoinService;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;

/**
 * Tests for BitcoinService, focusing on:
 * - SSL verification behaviour (configurable, on by default)
 * - Address validation / balance logic
 * - RPC error handling
 */
class BitcoinServiceTest extends PackageTestCase
{
    private function makeService(array $overrides = []): BitcoinService
    {
        $config = array_merge([
            'rpc_url'      => 'http://localhost:18332',
            'rpc_user'     => 'bitcoin',
            'rpc_password' => 'secret',
            'rpc_timeout'  => 30,
            'verify_ssl'   => true,
        ], $overrides);

        return new BitcoinService($config);
    }

    // ──────────────────────────── SSL ────────────────────────────

    public function test_ssl_verification_is_enabled_by_default(): void
    {
        $service = $this->makeService();
        // Reflection to read private property
        $ref = new \ReflectionProperty(BitcoinService::class, 'verifySsl');
        $ref->setAccessible(true);

        $this->assertTrue($ref->getValue($service));
    }

    public function test_ssl_verification_can_be_disabled_via_config(): void
    {
        $service = $this->makeService(['verify_ssl' => false]);
        $ref = new \ReflectionProperty(BitcoinService::class, 'verifySsl');
        $ref->setAccessible(true);

        $this->assertFalse($ref->getValue($service));
    }

    public function test_ssl_defaults_to_true_when_key_absent(): void
    {
        // verify_ssl key entirely missing from config
        $config = [
            'rpc_url'      => 'http://localhost:18332',
            'rpc_user'     => 'bitcoin',
            'rpc_password' => 'secret',
            'rpc_timeout'  => 30,
        ];
        $service = new BitcoinService($config);
        $ref = new \ReflectionProperty(BitcoinService::class, 'verifySsl');
        $ref->setAccessible(true);

        $this->assertTrue($ref->getValue($service), 'verifySsl must default to true when not explicitly configured.');
    }

    // ──────────────────────────── RPC calls (faked) ──────────────────────────

    public function test_generate_address_returns_address_from_rpc(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => 'tb1qfakeaddress0123456789',
                'error'  => null,
                'id'     => 'btc_test',
            ]),
        ]);

        $service = $this->makeService();
        $address = $service->generateAddress();

        $this->assertSame('tb1qfakeaddress0123456789', $address);
    }

    public function test_generate_address_throws_on_rpc_error(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => null,
                'error'  => ['code' => -18, 'message' => 'No wallet loaded'],
                'id'     => 'btc_test',
            ]),
        ]);

        $this->expectException(BlockchainException::class);

        $service = $this->makeService();
        $service->generateAddress();
    }

    public function test_get_balance_sums_unspent_outputs(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => [
                    ['amount' => 0.5],
                    ['amount' => 0.25],
                ],
                'error' => null,
                'id'    => 'btc_test',
            ]),
        ]);

        $service = $this->makeService();
        $balance = $service->getBalance('tb1qfakeaddress');

        $this->assertEqualsWithDelta(0.75, $balance, 0.00001);
    }

    public function test_get_balance_returns_zero_when_no_utxos(): void
    {
        Http::fake([
            '*' => Http::response([
                'result' => [],
                'error'  => null,
                'id'     => 'btc_test',
            ]),
        ]);

        $service = $this->makeService();
        $balance = $service->getBalance('tb1qfakeaddress');

        $this->assertSame(0.0, $balance);
    }

    public function test_get_confirmations_returns_zero_on_rpc_failure(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $service = $this->makeService();
        $confirmations = $service->getConfirmations('deadbeef');

        // Must not throw; gracefully returns 0
        $this->assertSame(0, $confirmations);
    }

    public function test_estimate_fee_falls_back_on_rpc_failure(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $service = $this->makeService();
        $fee = $service->estimateFee();

        $this->assertGreaterThan(0, $fee);
    }

    public function test_rpc_call_throws_blockchain_exception_on_network_error(): void
    {
        Http::fake(['*' => Http::response(null, 503)]);

        $this->expectException(BlockchainException::class);

        $service = $this->makeService();
        // validateAddress triggers rpcCall and propagates the exception
        $service->validateAddress('tb1qfakeaddress');
    }
}

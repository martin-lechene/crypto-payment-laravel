<?php

namespace MartinLechene\CryptoPayments\Tests\Unit;

use MartinLechene\CryptoPayments\Tests\PackageTestCase;

/**
 * Tests for the crypto-payments configuration file.
 */
class ConfigTest extends PackageTestCase
{
    public function test_config_is_loaded(): void
    {
        $config = config('crypto-payments');
        $this->assertIsArray($config);
        $this->assertArrayHasKey('networks', $config);
        $this->assertArrayHasKey('confirmation_blocks', $config);
        $this->assertArrayHasKey('fees', $config);
    }

    public function test_default_network_is_testnet(): void
    {
        $this->assertSame('testnet', config('crypto-payments.default_network'));
    }

    public function test_ethereum_testnet_uses_sepolia_chain_id(): void
    {
        $chainId = config('crypto-payments.networks.testnet.ethereum.chain_id');
        $this->assertSame(11155111, $chainId, 'Testnet must use Sepolia (chain_id 11155111), not the deprecated Goerli (5).');
    }

    public function test_ethereum_mainnet_uses_correct_chain_id(): void
    {
        $chainId = config('crypto-payments.networks.mainnet.ethereum.chain_id');
        $this->assertSame(1, $chainId);
    }

    public function test_bitcoin_testnet_ssl_verification_is_enabled_by_default(): void
    {
        $verifySsl = config('crypto-payments.networks.testnet.bitcoin.verify_ssl');
        $this->assertTrue($verifySsl, 'SSL verification must be enabled by default for Bitcoin testnet.');
    }

    public function test_bitcoin_mainnet_ssl_verification_is_enabled_by_default(): void
    {
        $verifySsl = config('crypto-payments.networks.mainnet.bitcoin.verify_ssl');
        $this->assertTrue($verifySsl, 'SSL verification must be enabled by default for Bitcoin mainnet.');
    }

    public function test_supported_currencies_contains_btc_and_eth(): void
    {
        $currencies = config('crypto-payments.supported_currencies');
        $this->assertContains('BTC', $currencies);
        $this->assertContains('ETH', $currencies);
    }

    public function test_confirmation_blocks_are_positive(): void
    {
        $this->assertGreaterThan(0, config('crypto-payments.confirmation_blocks.bitcoin'));
        $this->assertGreaterThan(0, config('crypto-payments.confirmation_blocks.ethereum'));
    }

    public function test_webhook_retry_attempts_are_configured(): void
    {
        $this->assertGreaterThan(0, config('crypto-payments.webhooks.retry_attempts'));
    }

    public function test_exchange_rate_cache_duration_is_set(): void
    {
        $this->assertGreaterThan(0, config('crypto-payments.exchange_rates.cache_duration'));
    }
}

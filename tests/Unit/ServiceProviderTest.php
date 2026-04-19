<?php

namespace MartinLechene\CryptoPayments\Tests\Unit;

use MartinLechene\CryptoPayments\Tests\PackageTestCase;

use MartinLechene\CryptoPayments\CryptoPaymentsServiceProvider;
use MartinLechene\CryptoPayments\Services\BitcoinService;
use MartinLechene\CryptoPayments\Services\EthereumService;
use MartinLechene\CryptoPayments\Services\ExchangeRateService;
use MartinLechene\CryptoPayments\Services\PaymentManager;
use MartinLechene\CryptoPayments\Services\WebhookManager;
use MartinLechene\CryptoPayments\Services\EncryptionService;

/**
 * Tests that the ServiceProvider correctly binds all services into the container.
 */
class ServiceProviderTest extends PackageTestCase
{
    public function test_provider_registers_bitcoin_service(): void
    {
        $this->assertInstanceOf(BitcoinService::class, $this->app->make(BitcoinService::class));
    }

    public function test_provider_registers_ethereum_service(): void
    {
        $this->assertInstanceOf(EthereumService::class, $this->app->make(EthereumService::class));
    }

    public function test_provider_registers_exchange_rate_service(): void
    {
        $this->assertInstanceOf(ExchangeRateService::class, $this->app->make(ExchangeRateService::class));
    }

    public function test_provider_registers_webhook_manager(): void
    {
        $this->assertInstanceOf(WebhookManager::class, $this->app->make(WebhookManager::class));
    }

    public function test_provider_registers_encryption_service(): void
    {
        $this->assertInstanceOf(EncryptionService::class, $this->app->make(EncryptionService::class));
    }

    public function test_provider_registers_payment_manager(): void
    {
        $this->assertInstanceOf(PaymentManager::class, $this->app->make(PaymentManager::class));
    }

    public function test_bitcoin_service_is_singleton(): void
    {
        $a = $this->app->make(BitcoinService::class);
        $b = $this->app->make(BitcoinService::class);
        $this->assertSame($a, $b);
    }

    public function test_config_is_published(): void
    {
        $config = config('crypto-payments');
        $this->assertNotNull($config);
        $this->assertIsArray($config);
    }
}

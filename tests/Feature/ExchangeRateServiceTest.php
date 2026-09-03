<?php

namespace MartinLechene\CryptoPayments\Tests\Feature;

use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\ExchangeRateException;
use MartinLechene\CryptoPayments\Models\ExchangeRate;
use MartinLechene\CryptoPayments\Services\ExchangeRateService;
use MartinLechene\CryptoPayments\Tests\TestCase;
use RuntimeException;

class ExchangeRateServiceTest extends TestCase
{
    public function test_recupere_le_taux_depuis_coingecko_et_le_persiste(): void
    {
            Http::fake([
                'api.coingecko.com/*' => fn () => Http::response(['bitcoin' => ['eur' => 50000, 'eur_market_cap' => 1, 'eur_24h_vol' => 2, 'eur_24h_change' => 3]]),
            ]);

            $service = new ExchangeRateService;

        $this->assertSame(50000.0, $service->getRate('BTC', 'EUR'));
        $this->assertTrue(ExchangeRate::for('BTC', 'EUR')->exists());
        $this->assertSame(50000.0, (float) ExchangeRate::for('BTC', 'EUR')->first()->rate);
    }

    public function test_utilise_le_cache_sans_appeler_l_api_une_seconde_fois(): void
    {
            $calls = 0;

            Http::fake([
                'api.coingecko.com/*' => function () use (&$calls) {
                    $calls++;

                    return Http::response(['bitcoin' => ['usd' => 60000]]);
                },
            ]);

            $service = new ExchangeRateService;

            $service->getRate('BTC', 'USD');
            $service->getRate('BTC', 'USD');

        $this->assertSame(1, $calls);
    }

    public function test_retourne_le_taux_frais_depuis_la_base_sans_appel_api(): void
    {
            ExchangeRate::create([
                'crypto_currency' => 'BTC',
                'fiat_currency' => 'USD',
                'rate' => 12345,
                'source' => 'seed',
                'updated_at' => now(),
            ]);

            Http::fake([
                'api.coingecko.com/*' => fn () => throw new RuntimeException('L API ne devrait pas être appelée'),
            ]);

            $service = new ExchangeRateService;

        $this->assertSame(12345.0, $service->getRate('BTC', 'USD'));
    }

    public function test_convertit_entre_crypto_et_fiat(): void
    {
            Http::fake([
                'api.coingecko.com/*' => fn () => Http::response(['ethereum' => ['usd' => 3000]]),
            ]);

            $service = new ExchangeRateService;

        $this->assertSame(6000.0, $service->convertToFiat('ETH', 2, 'USD'));
        $this->assertSame(2.0, $service->convertToCrypto('ETH', 6000, 'USD'));
    }

    public function test_leve_une_exception_quand_le_taux_est_introuvable(): void
    {
        $this->expectException(ExchangeRateException::class);

        Http::fake([
            'api.coingecko.com/*' => fn () => Http::response([], 200),
        ]);

        $service = new ExchangeRateService;

        $service->getRate('BTC', 'USD');
    }
}

<?php

use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\ExchangeRateException;
use MartinLechene\CryptoPayments\Models\ExchangeRate;
use MartinLechene\CryptoPayments\Services\ExchangeRateService;

it('récupère le taux depuis CoinGecko et le persiste', function () {
    Http::fake([
        'api.coingecko.com/*' => fn () => Http::response(['bitcoin' => ['eur' => 50000, 'eur_market_cap' => 1, 'eur_24h_vol' => 2, 'eur_24h_change' => 3]]),
    ]);

    $service = new ExchangeRateService;

    expect($service->getRate('BTC', 'EUR'))->toBe(50000.0);
    expect(ExchangeRate::for('BTC', 'EUR')->exists())->toBeTrue();
    expect((float) ExchangeRate::for('BTC', 'EUR')->first()->rate)->toBe(50000.0);
});

it('utilise le cache sans appeler l API une seconde fois', function () {
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

    expect($calls)->toBe(1);
});

it('retourne le taux frais depuis la base sans appel API', function () {
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

    expect($service->getRate('BTC', 'USD'))->toBe(12345.0);
});

it('convertit entre crypto et fiat', function () {
    Http::fake([
        'api.coingecko.com/*' => fn () => Http::response(['ethereum' => ['usd' => 3000]]),
    ]);

    $service = new ExchangeRateService;

    expect($service->convertToFiat('ETH', 2, 'USD'))->toBe(6000.0);
    expect($service->convertToCrypto('ETH', 6000, 'USD'))->toBe(2.0);
});

it('lève une exception quand le taux est introuvable', function () {
    Http::fake([
        'api.coingecko.com/*' => fn () => Http::response([], 200),
    ]);

    $service = new ExchangeRateService;

    $service->getRate('BTC', 'USD');
})->throws(ExchangeRateException::class);

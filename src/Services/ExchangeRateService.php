<?php

namespace MartinLechene\CryptoPayments\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MartinLechene\CryptoPayments\Models\ExchangeRate;
use MartinLechene\CryptoPayments\Exceptions\ExchangeRateException;

class ExchangeRateService
{
    protected string $provider;
    protected string $apiUrl;
    protected int $cacheDuration;
    
    public function __construct()
    {
        $this->provider = config('crypto-payments.exchange_rates.provider', 'coingecko');
        $this->apiUrl = config('crypto-payments.exchange_rates.coingecko_api_url');
        $this->cacheDuration = config('crypto-payments.exchange_rates.cache_duration', 300);
    }
    
    public function getRate(string $crypto, string $fiat = 'USD'): float
    {
        $crypto = strtoupper($crypto);
        $fiat = strtoupper($fiat);
        
        $cacheKey = "crypto_rate_{$crypto}_{$fiat}";
        
        return Cache::remember($cacheKey, $this->cacheDuration, function () use ($crypto, $fiat) {
            // Vérifier la base de données d'abord
            $dbRate = ExchangeRate::for($crypto, $fiat)->latest()->first();
            
            if ($dbRate && $dbRate->updated_at->diffInSeconds() < $this->cacheDuration) {
                return (float) $dbRate->rate;
            }
            
            // Récupérer de l'API
            $rate = $this->fetchRate($crypto, $fiat);
            
            // Sauvegarder en BD
            ExchangeRate::updateOrCreate(
                ['crypto_currency' => $crypto, 'fiat_currency' => $fiat],
                ['rate' => $rate, 'source' => $this->provider]
            );
            
            return $rate;
        });
    }
    
    public function convertToFiat(string $crypto, float $amount, string $fiat = 'USD'): float
    {
        $rate = $this->getRate($crypto, $fiat);
        return $amount * $rate;
    }
    
    public function convertToCrypto(string $crypto, float $amount, string $fiat = 'USD'): float
    {
        $rate = $this->getRate($crypto, $fiat);
        return $amount / $rate;
    }
    
    public function refreshAllRates(): void
    {
        foreach (config('crypto-payments.supported_currencies') as $crypto) {
            foreach (['USD', 'EUR', 'GBP'] as $fiat) {
                try {
                    $this->getRate($crypto, $fiat);
                } catch (\Exception $e) {
                    Log::warning("Erreur refresh taux {$crypto}/{$fiat}: " . $e->getMessage());
                }
            }
        }
    }
    
    protected function fetchRate(string $crypto, string $fiat): float
    {
        try {
            $cryptoId = $this->getCryptoId($crypto);
            
            $response = Http::timeout(10)->get("{$this->apiUrl}/simple/price", [
                'ids' => $cryptoId,
                'vs_currencies' => strtolower($fiat),
                'include_market_cap' => true,
                'include_24hr_vol' => true,
                'include_24hr_change' => true,
            ]);
            
            if (!$response->successful()) {
                throw new ExchangeRateException("API indisponible: " . $response->status());
            }
            
            $data = $response->json();
            $rate = $data[$cryptoId][strtolower($fiat)] ?? null;
            
            if ($rate === null) {
                throw new ExchangeRateException("Taux non trouvé pour {$crypto}/{$fiat}");
            }
            
            return (float) $rate;
        } catch (\Exception $e) {
            throw new ExchangeRateException("Erreur récupération taux: " . $e->getMessage());
        }
    }
    
    protected function getCryptoId(string $symbol): string
    {
        return match (strtolower($symbol)) {
            'btc' => 'bitcoin',
            'eth' => 'ethereum',
            'usdc' => 'usd-coin',
            'usdt' => 'tether',
            default => strtolower($symbol),
        };
    }
}


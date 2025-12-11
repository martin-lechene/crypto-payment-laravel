<?php

namespace MartinLechene\CryptoPayments\Console\Commands;

use Illuminate\Console\Command;
use MartinLechene\CryptoPayments\Services\BitcoinService;
use MartinLechene\CryptoPayments\Services\EthereumService;
use MartinLechene\CryptoPayments\Models\CryptoAddress;

class GenerateAddresses extends Command
{
    protected $signature = 'crypto:generate-addresses {currency=BTC} {--count=10} {--merchant-id=}';
    protected $description = 'Générer des adresses crypto pour les paiements';
    
    public function handle(BitcoinService $btc, EthereumService $eth): int
    {
        $currency = strtoupper($this->argument('currency'));
        $count = (int) $this->option('count');
        $merchantId = $this->option('merchant-id');
        
        if (!in_array($currency, ['BTC', 'ETH'])) {
            $this->error("Devise non supportée: $currency");
            return 1;
        }
        
        $service = match ($currency) {
            'BTC' => $btc,
            'ETH' => $eth,
        };
        
        $this->info("Génération de $count adresses $currency...");
        
        for ($i = 0; $i < $count; $i++) {
            try {
                $address = match ($currency) {
                    'BTC' => $service->generateAddress(),
                    'ETH' => $service->generateAddress()['address'],
                };
                
                CryptoAddress::create([
                    'merchant_id' => $merchantId,
                    'address' => $address,
                    'currency' => $currency,
                    'label' => "Auto-generated-" . ($i + 1),
                    'is_active' => true,
                ]);
                
                $this->line("✓ Adresse " . ($i + 1) . ": $address");
            } catch (\Exception $e) {
                $this->error("Erreur génération adresse " . ($i + 1) . ": " . $e->getMessage());
            }
        }
        
        $this->info("Génération terminée!");
        return 0;
    }
}


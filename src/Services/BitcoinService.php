<?php

namespace MartinLechene\CryptoPayments\Services;

use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;
use MartinLechene\CryptoPayments\Exceptions\InvalidAddressException;

class BitcoinService
{
    protected string $rpcUrl;
    protected string $rpcUser;
    protected string $rpcPassword;
    protected int $requiredConfirmations;
    protected int $timeout;
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->rpcUrl = $config['rpc_url'];
        $this->rpcUser = $config['rpc_user'];
        $this->rpcPassword = $config['rpc_password'];
        $this->timeout = $config['rpc_timeout'] ?? 30;
        $this->requiredConfirmations = config('crypto-payments.confirmation_blocks.bitcoin', 3);
    }
    
    public function generateAddress(string $label = '', string $derivationPath = null): string
    {
        try {
            if ($derivationPath && $this->config['address_derivation'] === 'bip44') {
                return $this->generateHierarchicalAddress($derivationPath);
            }
            
            $response = $this->rpcCall('getnewaddress', [$label, 'bech32']);
            
            if (isset($response['error']) && $response['error']) {
                throw new BlockchainException('Erreur génération adresse: ' . $response['error']['message']);
            }
            
            return $response['result'];
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible de générer l\'adresse: ' . $e->getMessage());
        }
    }
    
    public function validateAddress(string $address): bool
    {
        try {
            $response = $this->rpcCall('validateaddress', [$address]);
            return $response['result']['isvalid'] ?? false;
        } catch (\Exception $e) {
            throw new InvalidAddressException('Validation échouée: ' . $e->getMessage());
        }
    }
    
    public function getBalance(string $address): float
    {
        try {
            // Utiliser listunspent pour obtenir le solde
            $response = $this->rpcCall('listunspent', [0, 9999999, [$address]]);
            $result = $response['result'] ?? [];
            
            $balance = 0;
            foreach ($result as $output) {
                $balance += $output['amount'] ?? 0;
            }
            
            return (float) $balance;
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur récupération solde: ' . $e->getMessage());
        }
    }
    
    public function getTransaction(string $txHash): array
    {
        try {
            $response = $this->rpcCall('getrawtransaction', [$txHash, true]);
            
            if (isset($response['error']) && $response['error']) {
                return [];
            }
            
            return $response['result'] ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function getConfirmations(string $txHash): int
    {
        try {
            $tx = $this->getTransaction($txHash);
            return max(0, $tx['confirmations'] ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    public function estimateFee(int $confirmationTarget = 2): float
    {
        try {
            $response = $this->rpcCall('estimatesmartfee', [$confirmationTarget]);
            return $response['result']['feerate'] ?? 0.001;
        } catch (\Exception $e) {
            return 0.001; // Fallback
        }
    }
    
    public function sendTransaction(string $to, float $amount, string $feerate = 'standard'): string
    {
        try {
            $feeData = $this->config['fees'][$feerate] ?? $this->config['fees']['standard'];
            
            $response = $this->rpcCall('sendtoaddress', [
                $to,
                $amount,
                'Payment',
                'Crypto Payment',
                false,
                false,
                1,
                'UNSET'
            ]);
            
            if (isset($response['error']) && $response['error']) {
                throw new BlockchainException($response['error']['message']);
            }
            
            return $response['result'];
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur envoi transaction: ' . $e->getMessage());
        }
    }
    
    protected function generateHierarchicalAddress(string $derivationPath): string
    {
        // Implémentation BIP44 - nécessite une bibliothèque comme bitwasp/bitcoin
        return $this->generateAddress('', null);
    }
    
    protected function rpcCall(string $method, array $params = []): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => uniqid('btc_', true),
            'method' => $method,
            'params' => $params,
        ];
        
        try {
            $response = Http::withBasicAuth($this->rpcUser, $this->rpcPassword)
                ->timeout($this->timeout)
                ->withoutVerifying()
                ->post($this->rpcUrl, $payload);
            
            return $response->json();
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur RPC Bitcoin: ' . $e->getMessage());
        }
    }
}


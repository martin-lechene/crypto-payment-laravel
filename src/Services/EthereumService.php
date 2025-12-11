<?php

namespace MartinLechene\CryptoPayments\Services;

use Web3\Web3;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;

class EthereumService
{
    protected Web3 $web3;
    protected int $chainId;
    protected int $requiredConfirmations;
    protected string $rpcUrl;
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->rpcUrl = $config['rpc_url'];
        $this->chainId = $config['chain_id'] ?? 1;
        
        try {
            $this->web3 = new Web3(
                new HttpProvider(
                    new HttpRequestManager($this->rpcUrl, $config['rpc_timeout'] ?? 30)
                )
            );
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible de se connecter à Ethereum: ' . $e->getMessage());
        }
        
        $this->requiredConfirmations = config('crypto-payments.confirmation_blocks.ethereum', 12);
    }
    
    public function generateAddress(): array
    {
        try {
            $account = [];
            
            $this->web3->personal->newAccount('', function ($err, $result) use (&$account) {
                if ($err) {
                    throw new BlockchainException('Erreur création compte: ' . $err->getMessage());
                }
                $account = ['address' => $result];
            });
            
            return $account;
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible de générer l\'adresse: ' . $e->getMessage());
        }
    }
    
    public function validateAddress(string $address): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }
    
    public function getBalance(string $address): string
    {
        try {
            $balance = '';
            
            $this->web3->eth->getBalance($address, function ($err, $result) use (&$balance) {
                if ($err) {
                    throw new BlockchainException('Erreur balance: ' . $err->getMessage());
                }
                $balance = $this->web3->fromWei($result, 'ether');
            });
            
            return $balance;
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible de récupérer le solde: ' . $e->getMessage());
        }
    }
    
    public function getTransaction(string $txHash): array
    {
        try {
            $tx = [];
            
            $this->web3->eth->getTransactionByHash($txHash, function ($err, $result) use (&$tx) {
                if ($err) {
                    throw new BlockchainException('Transaction non trouvée');
                }
                $tx = (array)$result;
            });
            
            return $tx;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function getTransactionReceipt(string $txHash): array
    {
        try {
            $receipt = [];
            
            $this->web3->eth->getTransactionReceipt($txHash, function ($err, $result) use (&$receipt) {
                if ($err) {
                    throw new BlockchainException('Receipt non trouvé');
                }
                $receipt = (array)$result;
            });
            
            return $receipt;
        } catch (\Exception $e) {
            return [];
        }
    }
    
    public function getConfirmations(string $txHash): int
    {
        try {
            $receipt = $this->getTransactionReceipt($txHash);
            
            if (empty($receipt)) {
                return 0;
            }
            
            $blockNumber = 0;
            $this->web3->eth->blockNumber(function ($err, $result) use (&$blockNumber) {
                if ($err) {
                    throw new BlockchainException('Erreur blockNumber: ' . $err->getMessage());
                }
                $blockNumber = intval($result);
            });
            
            $txBlockNumber = intval($receipt['blockNumber'] ?? 0);
            return max(0, $blockNumber - $txBlockNumber);
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    public function estimateGas(string $from, string $to, string $amount): string
    {
        try {
            $gasEstimate = '';
            
            $this->web3->eth->estimateGas([
                'from' => $from,
                'to' => $to,
                'value' => $this->web3->toWei($amount, 'ether'),
            ], function ($err, $result) use (&$gasEstimate) {
                if ($err) {
                    throw new BlockchainException('Erreur estimation gas: ' . $err->getMessage());
                }
                $gasEstimate = $result;
            });
            
            return $gasEstimate;
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible d\'estimer le gas: ' . $e->getMessage());
        }
    }
    
    public function getGasPrice(): string
    {
        try {
            $gasPrice = '';
            
            $this->web3->eth->gasPrice(function ($err, $result) use (&$gasPrice) {
                if ($err) {
                    throw new BlockchainException('Erreur gas price: ' . $err->getMessage());
                }
                $gasPrice = $this->web3->fromWei($result, 'gwei');
            });
            
            return $gasPrice;
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible de récupérer le gas price: ' . $e->getMessage());
        }
    }
    
    public function sendTransaction(
        string $from,
        string $to,
        string $amount,
        array $options = []
    ): string {
        try {
            $gasPrice = $options['gasPrice'] ?? $this->getGasPrice();
            $gas = $options['gas'] ?? $this->estimateGas($from, $to, $amount);
            
            // À implémenter avec la gestion des clés privées sécurisée
            throw new BlockchainException('Envoi de transaction non implémenté');
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur envoi transaction: ' . $e->getMessage());
        }
    }
}


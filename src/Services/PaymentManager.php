<?php

namespace MartinLechene\CryptoPayments\Services;

use MartinLechene\CryptoPayments\Models\CryptoPayment;
use MartinLechene\CryptoPayments\Models\CryptoAddress;
use MartinLechene\CryptoPayments\Models\BlockchainTransaction;
use MartinLechene\CryptoPayments\Exceptions\PaymentException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentManager
{
    protected BitcoinService $bitcoinService;
    protected EthereumService $ethereumService;
    protected ExchangeRateService $exchangeRateService;
    protected WebhookManager $webhookManager;
    protected EncryptionService $encryptionService;
    
    public function __construct(
        BitcoinService $bitcoinService,
        EthereumService $ethereumService,
        ExchangeRateService $exchangeRateService,
        WebhookManager $webhookManager,
        EncryptionService $encryptionService
    ) {
        $this->bitcoinService = $bitcoinService;
        $this->ethereumService = $ethereumService;
        $this->exchangeRateService = $exchangeRateService;
        $this->webhookManager = $webhookManager;
        $this->encryptionService = $encryptionService;
    }
    
    public function createPaymentRequest(
        string $currency,
        float $amountFiat,
        string $fiatCurrency = 'USD',
        array $options = []
    ): CryptoPayment {
        try {
            DB::beginTransaction();
            
            $currency = strtoupper($currency);
            
            if (!in_array($currency, config('crypto-payments.supported_currencies'))) {
                throw new PaymentException("Devise non supportée: $currency");
            }
            
            // Récupérer le taux de change
            $exchangeRate = $this->exchangeRateService->getRate($currency, $fiatCurrency);
            $amountCrypto = $amountFiat / $exchangeRate;
            
            // Générer une adresse de paiement
            $address = $this->generateAddress($currency);
            
            // Calculer les frais
            $platformFeePercentage = $options['fee_percentage'] ?? 0;
            $platformFee = ($amountFiat * $platformFeePercentage) / 100;
            
            // Créer le paiement
            $payment = CryptoPayment::create([
                'currency' => $currency,
                'amount_crypto' => $amountCrypto,
                'amount_fiat' => $amountFiat,
                'fiat_currency' => $fiatCurrency,
                'wallet_address' => $address,
                'exchange_rate' => $exchangeRate,
                'platform_fee' => $platformFee,
                'fee_percentage' => $platformFeePercentage,
                'status' => CryptoPayment::STATUS_PENDING,
                'confirmations' => 0,
                'required_confirmations' => $this->getRequiredConfirmations($currency),
                'expires_at' => now()->addMinutes(
                    $options['expires_in_minutes'] ?? config('crypto-payments.payment_timeouts.pending', 15)
                ),
                'metadata' => $options['metadata'] ?? [],
                'description' => $options['description'] ?? null,
                'order_id' => $options['order_id'] ?? null,
                'user_id' => $options['user_id'] ?? null,
                'merchant_id' => $options['merchant_id'] ?? null,
                'payment_method' => $options['payment_method'] ?? 'native',
            ]);
            
            // Enregistrer l'adresse crypto si elle n'existe pas
            $this->registerAddress($address, $currency, $payment->merchant_id);
            
            // Trigger webhook
            $this->webhookManager->trigger($payment, 'payment_created');
            
            $payment->logAudit('payment_created', [
                'amount_fiat' => $amountFiat,
                'amount_crypto' => $amountCrypto,
                'exchange_rate' => $exchangeRate,
            ]);
            
            DB::commit();
            
            return $payment;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PaymentException('Erreur création paiement: ' . $e->getMessage());
        }
    }
    
    public function checkPaymentStatus(CryptoPayment $payment): void
    {
        try {
            // Vérifier expiration
            if ($payment->expires_at && $payment->expires_at->isPast() && $payment->status === CryptoPayment::STATUS_PENDING) {
                $payment->updateStatus(CryptoPayment::STATUS_EXPIRED);
                $this->webhookManager->trigger($payment, 'payment_expired');
                return;
            }
            
            // Si pas de hash de transaction, chercher
            if (!$payment->transaction_hash) {
                $txHash = $this->findTransaction($payment);
                if ($txHash) {
                    $payment->transaction_hash = $txHash;
                    $payment->save();
                } else {
                    return;
                }
            }
            
            // Vérifier les confirmations
            $confirmations = $this->getTransactionConfirmations($payment->currency, $payment->transaction_hash);
            $payment->confirmations = $confirmations;
            
            // Mettre à jour le statut selon les confirmations
            if ($confirmations > 0 && $payment->status === CryptoPayment::STATUS_PENDING) {
                $payment->updateStatus(CryptoPayment::STATUS_CONFIRMING);
                $this->webhookManager->trigger($payment, 'payment_confirming');
            }
            
            if ($payment->isConfirmed()) {
                $payment->updateStatus(CryptoPayment::STATUS_CONFIRMED);
                $this->webhookManager->trigger($payment, 'payment_confirmed');
            }
            
            $payment->save();
        } catch (\Exception $e) {
            Log::error('Erreur vérification paiement: ' . $e->getMessage(), ['payment_id' => $payment->id]);
        }
    }
    
    public function completePayment(CryptoPayment $payment): void
    {
        if ($payment->status === CryptoPayment::STATUS_COMPLETED) {
            return;
        }
        
        $payment->markAsCompleted();
        $this->webhookManager->trigger($payment, 'payment_completed');
    }
    
    public function refundPayment(CryptoPayment $payment, string $reason = null): void
    {
        $payment->markAsRefunded();
        
        if ($reason) {
            $payment->logAudit('refund_initiated', ['reason' => $reason]);
        }
    }
    
    protected function generateAddress(string $currency): string
    {
        try {
            return match (strtoupper($currency)) {
                'BTC' => $this->bitcoinService->generateAddress(),
                'ETH' => $this->ethereumService->generateAddress()['address'],
                default => throw new PaymentException("Devise non supportée: $currency"),
            };
        } catch (\Exception $e) {
            throw new PaymentException("Erreur génération adresse: " . $e->getMessage());
        }
    }
    
    protected function registerAddress(string $address, string $currency, $merchantId = null): void
    {
        CryptoAddress::firstOrCreate(
            ['address' => $address, 'currency' => $currency],
            [
                'merchant_id' => $merchantId,
                'label' => "Auto-generated-" . now()->format('Y-m-d H:i:s'),
                'is_active' => true,
            ]
        );
    }
    
    protected function findTransaction(CryptoPayment $payment): ?string
    {
        // À implémenter avec indexeurs blockchain ou API tierces
        // Pour Bitcoin: utiliser BlockchainAPI ou similar
        // Pour Ethereum: utiliser Etherscan API
        return null;
    }
    
    protected function getTransactionConfirmations(string $currency, string $txHash): int
    {
        try {
            return match (strtoupper($currency)) {
                'BTC' => $this->bitcoinService->getConfirmations($txHash),
                'ETH' => $this->ethereumService->getConfirmations($txHash),
                default => 0,
            };
        } catch (\Exception $e) {
            return 0;
        }
    }
    
    protected function getRequiredConfirmations(string $currency): int
    {
        return config('crypto-payments.confirmation_blocks.' . strtolower($currency), 2);
    }
}


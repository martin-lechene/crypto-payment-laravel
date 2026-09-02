<?php

namespace MartinLechene\CryptoPayments\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MartinLechene\CryptoPayments\Exceptions\PaymentException;
use MartinLechene\CryptoPayments\Models\BlockchainTransaction;
use MartinLechene\CryptoPayments\Models\CryptoAddress;
use MartinLechene\CryptoPayments\Models\CryptoPayment;
use MartinLechene\CryptoPayments\Support\HexMath;

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

            if (! in_array($currency, config('crypto-payments.supported_currencies'))) {
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
            throw new PaymentException('Erreur création paiement: '.$e->getMessage());
        }
    }

    public function checkPaymentStatus(CryptoPayment $payment): void
    {
        try {
            $previousStatus = $payment->status;

            // Vérifier expiration
            if ($payment->expires_at && $payment->expires_at->isPast()
                && in_array($payment->status, [CryptoPayment::STATUS_PENDING, CryptoPayment::STATUS_CONFIRMING], true)) {
                $payment->updateStatus(CryptoPayment::STATUS_EXPIRED);
                $this->webhookManager->trigger($payment, 'payment_expired');

                return;
            }

            // Si pas de hash de transaction, chercher
            if (! $payment->transaction_hash) {
                $txHash = $this->findTransaction($payment);
                if ($txHash) {
                    $this->recordTransaction($payment, $txHash);
                    $payment->transaction_hash = $txHash;
                    $payment->save();
                } else {
                    return;
                }
            }

            // Vérifier les confirmations
            $confirmations = $this->getTransactionConfirmations($payment->currency, $payment->transaction_hash);
            $payment->confirmations = $confirmations;
            $payment->save();

            // Mettre à jour le statut selon les confirmations
            if ($previousStatus === CryptoPayment::STATUS_PENDING && $confirmations > 0) {
                $payment->updateStatus(CryptoPayment::STATUS_CONFIRMING);
                $this->webhookManager->trigger($payment, 'payment_confirming');
            }

            if ($payment->isConfirmed()
                && in_array($previousStatus, [CryptoPayment::STATUS_PENDING, CryptoPayment::STATUS_CONFIRMING], true)) {
                $payment->updateStatus(CryptoPayment::STATUS_CONFIRMED);
                $this->webhookManager->trigger($payment, 'payment_confirmed');
            }
        } catch (\Exception $e) {
            Log::error('Erreur vérification paiement: '.$e->getMessage(), ['payment_id' => $payment->id]);
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

    public function refundPayment(CryptoPayment $payment, ?string $reason = null): void
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
            throw new PaymentException('Erreur génération adresse: '.$e->getMessage());
        }
    }

    protected function registerAddress(string $address, string $currency, $merchantId = null): void
    {
        CryptoAddress::firstOrCreate(
            ['address' => $address, 'currency' => $currency],
            [
                'merchant_id' => $merchantId,
                'label' => 'Auto-generated-'.now()->format('Y-m-d H:i:s'),
                'is_active' => true,
            ]
        );
    }

    protected function findTransaction(CryptoPayment $payment): ?string
    {
        try {
            return match (strtoupper($payment->currency)) {
                'BTC' => $this->bitcoinService->findTransactionForAddress(
                    $payment->wallet_address,
                    (float) $payment->amount_crypto
                ),
                'ETH' => $this->ethereumService->findTransactionForAddress(
                    $payment->wallet_address,
                    (string) $payment->amount_crypto
                ),
                default => null,
            };
        } catch (\Exception $e) {
            Log::warning('Recherche de transaction impossible: '.$e->getMessage(), [
                'payment_id' => $payment->id,
                'currency' => $payment->currency,
            ]);

            return null;
        }
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
        $network = strtolower($currency);
        $network = match ($network) {
            'btc' => 'bitcoin',
            'eth' => 'ethereum',
            default => $network,
        };

        return config('crypto-payments.confirmation_blocks.'.$network, 2);
    }

    protected function recordTransaction(CryptoPayment $payment, string $txHash): void
    {
        if (BlockchainTransaction::where('tx_hash', $txHash)->exists()) {
            return;
        }

        $record = [
            'payment_id' => $payment->id,
            'tx_hash' => $txHash,
            'to_address' => $payment->wallet_address,
            'from_address' => 'unknown',
            'amount' => (float) $payment->amount_crypto,
        ];

        try {
            if (strtoupper($payment->currency) === 'ETH') {
                $tx = $this->ethereumService->getTransaction($txHash);

                if (! empty($tx)) {
                    $record['from_address'] = (string) ($tx['from'] ?? $record['from_address']);
                    $record['block_hash'] = isset($tx['blockHash']) ? (string) $tx['blockHash'] : null;
                    $record['block_number'] = isset($tx['blockNumber'])
                        ? HexMath::hexToInt((string) $tx['blockNumber'])
                        : null;
                    $record['amount'] = (float) HexMath::weiToEther((string) ($tx['value'] ?? '0'));
                    $record['nonce'] = isset($tx['nonce']) ? HexMath::hexToInt((string) $tx['nonce']) : null;
                    $record['input_data'] = $tx['input'] ?? null;
                    $record['raw_data'] = $tx;
                }
            } else {
                $raw = $this->bitcoinService->getTransaction($txHash);

                if (! empty($raw)) {
                    $value = 0.0;
                    foreach ($raw['vout'] ?? [] as $output) {
                        $destinations = $output['scriptPubKey']['addresses'] ?? [];
                        $dest = (string) ($destinations[0] ?? '');
                        if ($dest !== '' && strtolower($dest) === strtolower($payment->wallet_address)) {
                            $value += (float) ($output['value'] ?? 0);
                        }
                    }

                    $record['from_address'] = (string) ($raw['vin'][0]['txid'] ?? $record['from_address']);
                    $record['block_hash'] = $raw['blockhash'] ?? null;
                    $record['block_number'] = isset($raw['blockheight']) ? (int) $raw['blockheight'] : null;
                    $record['amount'] = $value;
                    $record['raw_data'] = $raw;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Détail transaction indisponible: '.$e->getMessage(), ['tx_hash' => $txHash]);
        }

        BlockchainTransaction::create($record);
    }

    public function forceRefundOnFailedConfirmation(CryptoPayment $payment): void
    {
        if (! in_array($payment->status, [CryptoPayment::STATUS_PENDING, CryptoPayment::STATUS_CONFIRMING], true)) {
            return;
        }

        $payment->updateStatus(CryptoPayment::STATUS_FAILED);
        $this->webhookManager->trigger($payment, 'payment_failed');
    }
}

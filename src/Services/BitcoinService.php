<?php

namespace MartinLechene\CryptoPayments\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;
use MartinLechene\CryptoPayments\Exceptions\InvalidAddressException;
use MartinLechene\CryptoPayments\Support\HexMath;

class BitcoinService
{
    protected string $rpcUrl;

    protected string $rpcUser;

    protected string $rpcPassword;

    protected int $requiredConfirmations;

    protected int $timeout;

    protected bool $verifySsl;

    protected ?string $explorerUrl;

    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->rpcUrl = (string) $config['rpc_url'];
        $this->rpcUser = (string) ($config['rpc_user'] ?? '');
        $this->rpcPassword = (string) ($config['rpc_password'] ?? '');
        $this->timeout = (int) ($config['rpc_timeout'] ?? 30);
        $this->verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $this->explorerUrl = $config['explorer_url'] ?? null;
        $this->requiredConfirmations = (int) config('crypto-payments.confirmation_blocks.bitcoin', 3);
    }

    public function generateAddress(string $label = '', ?string $derivationPath = null): string
    {
        try {
            $label = $derivationPath ? trim($label.' '.$derivationPath) : $label;

            $response = $this->rpcCall('getnewaddress', [$label, 'bech32']);

            if (! empty($response['error'])) {
                throw new BlockchainException('Erreur génération adresse: '.$this->errorMessage($response['error']));
            }

            $address = $response['result'] ?? null;

            if (! $address || ! $this->isValidAddressFormat((string) $address)) {
                throw new BlockchainException('Adresse invalide retournée par le nœud');
            }

            return (string) $address;
        } catch (BlockchainException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible de générer l\'adresse: '.$e->getMessage());
        }
    }

    public function validateAddress(string $address): bool
    {
        try {
            $response = $this->rpcCall('validateaddress', [$address]);

            if (! empty($response['error'])) {
                throw new InvalidAddressException('Validation échouée: '.$this->errorMessage($response['error']));
            }

            return (bool) ($response['result']['isvalid'] ?? false);
        } catch (InvalidAddressException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new InvalidAddressException('Validation échouée: '.$e->getMessage());
        }
    }

    /**
     * Recherche, via l'explorateur configuré (API Blockstream/Esplora),
     * une transaction dont un output crédite l'adresse cible avec au moins
     * le montant attendu. Retourne le txid ou null.
     */
    public function findTransactionForAddress(string $address, float $amountBtc): ?string
    {
        if (! $this->explorerUrl) {
            throw new BlockchainException('Explorateur Bitcoin non configuré (explorer_url)');
        }

        $expectedSats = HexMath::btcToSatoshi($amountBtc);

        $response = $this->http()->get(rtrim($this->explorerUrl, '/').'/address/'.rawurlencode($address).'/txs');

        if ($response->failed()) {
            throw new BlockchainException('Explorateur Bitcoin indisponible: HTTP '.$response->status());
        }

        $txs = $response->json();

        if (! is_array($txs)) {
            return null;
        }

        foreach ($txs as $tx) {
            if (! is_array($tx) || empty($tx['txid']) || ! is_array($tx['vout'] ?? null)) {
                continue;
            }

            foreach ($tx['vout'] as $output) {
                if (! is_array($output)) {
                    continue;
                }

                $destination = (string) ($output['scriptpubkey_address'] ?? '');
                if ($destination !== '' && strtolower($destination) === strtolower($address)) {
                    $receivedSats = (int) ($output['value'] ?? 0);
                    if ($receivedSats >= $expectedSats) {
                        return (string) $tx['txid'];
                    }
                }
            }
        }

        return null;
    }

    public function getBalance(string $address): float
    {
        try {
            $response = $this->rpcCall('listunspent', [0, 9999999, [$address]]);

            if (! empty($response['error'])) {
                throw new BlockchainException($this->errorMessage($response['error']));
            }

            $balance = 0.0;

            foreach ($response['result'] ?? [] as $output) {
                $balance += (float) ($output['amount'] ?? 0);
            }

            return $balance;
        } catch (BlockchainException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur récupération solde: '.$e->getMessage());
        }
    }

    public function getTransaction(string $txHash): array
    {
        try {
            $response = $this->rpcCall('getrawtransaction', [$txHash, true]);

            if (! empty($response['error'])) {
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

            return max(0, (int) ($tx['confirmations'] ?? 0));
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function estimateFee(int $confirmationTarget = 2): float
    {
        try {
            $response = $this->rpcCall('estimatesmartfee', [$confirmationTarget]);

            return (float) ($response['result']['feerate'] ?? 0.001);
        } catch (\Exception $e) {
            return 0.001;
        }
    }

    public function sendTransaction(string $to, float $amount, string $feerate = 'standard'): string
    {
        try {
            if (! $this->validateAddress($to)) {
                throw new InvalidAddressException('Adresse destination invalide: '.$to);
            }

            $response = $this->rpcCall('sendtoaddress', [
                $to,
                $amount,
                'Payment',
                'Crypto Payment',
                false,
                false,
                1,
                'UNSET',
            ]);

            if (! empty($response['error'])) {
                throw new BlockchainException($this->errorMessage($response['error']));
            }

            $txHash = $response['result'] ?? null;

            if (! $txHash || preg_match('/^[a-fA-F0-9]{64}$/', (string) $txHash) !== 1) {
                throw new BlockchainException('Hash invalide retourné par le nœud');
            }

            return (string) $txHash;
        } catch (BlockchainException $e) {
            throw $e;
        } catch (InvalidAddressException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur envoi transaction: '.$e->getMessage());
        }
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
            $response = $this->http()->post($this->rpcUrl, $payload);

            if ($response->failed()) {
                throw new BlockchainException("Erreur RPC Bitcoin ({$method}): HTTP {$response->status()}");
            }

            return $response->json() ?? [];
        } catch (BlockchainException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur RPC Bitcoin: '.$e->getMessage());
        }
    }

    protected function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withOptions(['verify' => $this->verifySsl])
            ->when($this->rpcUser !== '' && $this->rpcPassword !== '', fn ($request) => $request->withBasicAuth(
                $this->rpcUser,
                $this->rpcPassword
            ));
    }

    protected function isValidAddressFormat(string $address): bool
    {
        if (preg_match('/^[123][1-9A-HJ-NP-Za-km-z]{25,34}$/', $address) === 1) {
            return true;
        }

        if (preg_match('/^(bc1|tb1|bcrt1)[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{14,80}$/', $address) === 1) {
            return true;
        }

        return false;
    }

    protected function errorMessage(mixed $error): string
    {
        if (is_array($error)) {
            return ($error['message'] ?? '') !== '' ? (string) $error['message'] : json_encode($error);
        }

        return (string) $error;
    }
}

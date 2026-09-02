<?php

namespace MartinLechene\CryptoPayments\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;
use MartinLechene\CryptoPayments\Support\HexMath;

class EthereumService
{
    protected string $rpcUrl;

    protected int $chainId;

    protected int $timeout;

    protected bool $verifySsl;

    protected string $rpcUser;

    protected string $rpcPassword;

    protected ?string $explorerUrl;

    protected ?string $explorerApiKey;

    protected array $config;

    protected int $requiredConfirmations;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->rpcUrl = (string) $config['rpc_url'];
        $this->chainId = (int) ($config['chain_id'] ?? 1);
        $this->timeout = (int) ($config['rpc_timeout'] ?? 30);
        $this->verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $this->rpcUser = (string) ($config['rpc_user'] ?? '');
        $this->rpcPassword = (string) ($config['rpc_password'] ?? '');
        $this->explorerUrl = $config['explorer_url'] ?? null;
        $this->explorerApiKey = $config['explorer_api_key'] ?? null;
        $this->requiredConfirmations = (int) config('crypto-payments.confirmation_blocks.ethereum', 12);
    }

    public function rpcUrl(): string
    {
        return $this->rpcUrl;
    }

    public function chainId(): int
    {
        return $this->chainId;
    }

    public function generateAddress(): array
    {
        try {
            $result = $this->rpc('personal_newAccount', ['']);

            if (! $result || ! preg_match('/^0x[a-fA-F0-9]{40}$/', (string) $result)) {
                throw new BlockchainException('Réponse personnelle_newAccount invalide');
            }

            return ['address' => (string) $result];
        } catch (BlockchainException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BlockchainException('Impossible de générer l\'adresse: '.$e->getMessage());
        }
    }

    public function validateAddress(string $address): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }

    public function normalizeAddress(string $address): string
    {
        return strtolower($address);
    }

    public function getBalance(string $address): string
    {
        $this->assertAddress($address);

        $result = $this->rpc('eth_getBalance', [$address, 'latest']);

        return HexMath::weiToEther($this->decodeHex($result));
    }

    public function getTransaction(string $txHash): array
    {
        $this->assertTxHash($txHash);

        try {
            $result = $this->rpc('eth_getTransactionByHash', [$txHash]);

            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getTransactionReceipt(string $txHash): array
    {
        $this->assertTxHash($txHash);

        try {
            $result = $this->rpc('eth_getTransactionReceipt', [$txHash]);

            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getBlockNumber(): int
    {
        $result = $this->rpc('eth_blockNumber', []);

        return $this->decodeHex($result);
    }

    public function getConfirmations(string $txHash): int
    {
        $receipt = $this->getTransactionReceipt($txHash);

        if (empty($receipt) || empty($receipt['blockNumber'])) {
            return 0;
        }

        $txBlock = $this->decodeHex($receipt['blockNumber']);
        $latestBlock = $this->getBlockNumber();

        return max(0, $latestBlock - $txBlock);
    }

    public function getTransactionStatus(string $txHash): ?bool
    {
        $receipt = $this->getTransactionReceipt($txHash);

        if (empty($receipt) || ! array_key_exists('status', $receipt)) {
            return null;
        }

        return $this->decodeHex($receipt['status']) === 1;
    }

    public function estimateGas(string $from, string $to, string $amountEth = '0'): string
    {
        $this->assertAddress($to);

        $result = $this->rpc('eth_estimateGas', [
            [
                'from' => $from,
                'to' => $to,
                'value' => $this->encodeHex(HexMath::etherToWei($amountEth)),
            ],
        ]);

        return $this->decodeHex($result);
    }

    public function getGasPrice(): string
    {
        $result = $this->rpc('eth_gasPrice', []);

        return $this->decodeHex($result);
    }

    public function getChainId(): int
    {
        try {
            $result = $this->rpc('eth_chainId', []);

            return $this->decodeHex($result);
        } catch (\Exception $e) {
            return $this->chainId;
        }
    }

    public function sendTransaction(
        string $from,
        string $to,
        string $amountEth,
        array $options = []
    ): string {
        try {
            $gasPrice = $options['gas_price'] ?? null;
            $gas = $options['gas'] ?? $options['gas_limit'] ?? null;

            if ($gasPrice === null) {
                $gasPriceWei = $this->getGasPrice();
                $gasPriceWei = HexMath::divideByInt(HexMath::multiplyByInt($gasPriceWei, 110), 100)[0];
            } else {
                $gasPriceWei = HexMath::etherToWei((string) $gasPrice);
            }

            if ($gas === null) {
                $gas = $this->estimateGas($from, $to, $amountEth) ?: '21000';
            } else {
                $gas = (string) $gas;
            }

            $tx = [
                'from' => $from,
                'to' => $to,
                'gas' => $this->encodeHex((string) $gas),
                'gasPrice' => $this->encodeHex($gasPriceWei),
                'value' => $this->encodeHex(HexMath::etherToWei($amountEth)),
            ];

            if (! empty($options['nonce'])) {
                $tx['nonce'] = $this->encodeHex((string) $options['nonce']);
            }

            $result = $this->rpc('eth_sendTransaction', [$tx]);

            if (! $result || ! preg_match('/^0x[a-fA-F0-9]{64}$/', (string) $result)) {
                throw new BlockchainException('Réponse eth_sendTransaction invalide');
            }

            return (string) $result;
        } catch (BlockchainException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new BlockchainException('Erreur envoi transaction: '.$e->getMessage());
        }
    }

    /**
     * Recherche un transfert ETH natif reçu sur l'adresse cible, via l'explorateur
     * configuré (Etherscan-compatible). Retourne le hash de la transaction si un
     * paiement couvrant le montant attendu est trouvé, null sinon.
     */
    public function findTransactionForAddress(string $address, string $amountEth): ?string
    {
        $this->assertAddress($address);

        if (! $this->explorerUrl) {
            throw new BlockchainException('Explorateur Ethereum non configuré (explorer_url)');
        }

        $expectedWei = HexMath::etherToWei($amountEth);

        $response = $this->http()->withOptions(['query' => array_filter([
            'module' => 'account',
            'action' => 'txlist',
            'address' => $address,
            'startblock' => '0',
            'endblock' => 'latest',
            'page' => '1',
            'offset' => '100',
            'sort' => 'asc',
            'apikey' => $this->explorerApiKey,
        ], fn ($value) => $value !== null)])->get($this->explorerUrl);

        $data = $response->json();

        if ($data['status'] !== '1' || ! is_array($data['result'] ?? null)) {
            return null;
        }

        foreach ($data['result'] as $tx) {
            if (! is_array($tx)) {
                continue;
            }

            if (($tx['isError'] ?? '0') !== '0') {
                continue;
            }

            $txTo = strtolower($tx['to'] ?? '');
            if ($txTo !== strtolower($address)) {
                continue;
            }

            $value = (string) ($tx['value'] ?? '0');
            if (HexMath::compare($value, $expectedWei) < 0) {
                continue;
            }

            return (string) $tx['hash'];
        }

        return null;
    }

    protected function rpc(string $method, array $params = []): mixed
    {
        $response = $this->http()->post($this->rpcUrl, [
            'jsonrpc' => '2.0',
            'id' => uniqid('eth_', true),
            'method' => $method,
            'params' => $params,
        ]);

        if ($response->failed()) {
            throw new BlockchainException("Erreur RPC Ethereum ({$method}): HTTP {$response->status()}");
        }

        $body = $response->json();

        if (isset($body['error'])) {
            $message = is_array($body['error'])
                ? ($body['error']['message'] ?? json_encode($body['error']))
                : (string) $body['error'];

            throw new BlockchainException("Erreur RPC Ethereum ({$method}): {$message}");
        }

        return $body['result'] ?? null;
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

    protected function decodeHex(mixed $value): int
    {
        if (! is_string($value)) {
            return 0;
        }

        return HexMath::hexToInt($value);
    }

    protected function encodeHex(string $decimal): string
    {
        return HexMath::decToHex($decimal);
    }

    private function assertAddress(string $address): void
    {
        if (! $this->validateAddress($address)) {
            throw new BlockchainException('Adresse Ethereum invalide: '.$address);
        }
    }

    private function assertTxHash(string $txHash): void
    {
        if (preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash) !== 1) {
            throw new BlockchainException('Hash de transaction invalide: '.$txHash);
        }
    }
}

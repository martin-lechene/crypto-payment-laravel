<?php

namespace MartinLechene\CryptoPayments\Tests\Unit;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;
use MartinLechene\CryptoPayments\Services\EthereumService;
use MartinLechene\CryptoPayments\Support\HexMath;
use MartinLechene\CryptoPayments\Tests\TestCase;

function ethService(array $config = []): EthereumService
{
    return new EthereumService(array_merge([
        'enabled' => true,
        'rpc_url' => 'http://localhost:8545',
        'chain_id' => 11155111,
        'rpc_timeout' => 5,
        'verify_ssl' => true,
        'explorer_url' => 'https://api-sepolia.etherscan.io/api',
        'explorer_api_key' => null,
    ], $config));
}
function ethRpc(array $byMethod): Closure
{
    return function (Request $request) use ($byMethod) {
        $body = json_decode($request->body(), true);
        $method = $body['method'] ?? '';

        if (($byMethod[$method] ?? null) instanceof Closure) {
            $payload = $byMethod[$method]($body['params'] ?? []);
        } elseif (array_key_exists($method, $byMethod)) {
            $payload = $byMethod[$method];
        } else {
            $payload = null;
        }

        return Http::response([
            'jsonrpc' => '2.0',
            'id' => $body['id'] ?? null,
            'result' => $payload,
        ]);
    };
}

class EthereumServiceTest extends TestCase
{
    public function test_accepte_et_rejette_les_adresses_ethereum(): void
    {
        $service = ethService();

        $this->assertTrue($service->validateAddress('0x'.str_repeat('a', 40)));
        $this->assertTrue($service->validateAddress('0x'.str_repeat('A', 40)));
        $this->assertFalse($service->validateAddress(str_repeat('a', 40)));
        $this->assertFalse($service->validateAddress('0x'.str_repeat('a', 39)));
        $this->assertFalse($service->validateAddress('0x'.str_repeat('g', 40)));
    }

    public function test_genere_une_adresse_via_personal_newaccount(): void
    {
        Http::fake([
            'localhost:8545*' => ethRpc([
                'personal_newAccount' => '0x'.str_repeat('12ab', 10),
            ]),
        ]);

        $service = ethService();

        $account = $service->generateAddress();

        $this->assertSame('0x'.str_repeat('12ab', 10), $account['address']);
    }

    public function test_retourne_le_solde_en_ether(): void
    {
        Http::fake([
            'localhost:8545*' => ethRpc([
                'eth_getBalance' => '0xde0b6b3a7640000',
            ]),
        ]);

        $this->assertSame('1', ethService()->getBalance('0x'.str_repeat('a', 40)));
    }

    public function test_retourne_0_confirmation_sans_receipt(): void
    {
        Http::fake([
            'localhost:8545*' => ethRpc([
                'eth_getTransactionReceipt' => null,
            ]),
        ]);

        $service = ethService();
        $hash = '0x'.str_repeat('ab', 32);

        $this->assertSame(0, $service->getConfirmations($hash));
    }

    public function test_calcule_les_confirmations_a_partir_du_blocknumber(): void
    {
        Http::fake([
            'localhost:8545*' => ethRpc([
                'eth_getTransactionReceipt' => ['blockNumber' => '0x10'],
                'eth_blockNumber' => '0x14',
            ]),
        ]);

        $service = ethService();
        $hash = '0x'.str_repeat('ab', 32);

        $this->assertSame(4, $service->getConfirmations($hash));
    }

    public function test_retourne_le_statut_de_transaction(): void
    {
        Http::fake([
            'localhost:8545*' => ethRpc([
                'eth_getTransactionReceipt' => ['status' => '0x1', 'blockNumber' => '0x10'],
            ]),
        ]);

        $service = ethService();
        $hash = '0x'.str_repeat('ab', 32);

        $this->assertTrue($service->getTransactionStatus($hash));
    }

    public function test_retourne_le_chain_id_du_noeud(): void
    {
        Http::fake([
            'localhost:8545*' => ethRpc([
                'eth_chainId' => '0xaa36a7',
            ]),
        ]);

        $this->assertSame(11155111, ethService()->getChainId());
    }

    public function test_leve_une_exception_blockchainexception_si_le_rpc_renvoie_une_erreur(): void
    {
        $this->expectException(BlockchainException::class);
        $this->expectExceptionMessage('Method not found');

        Http::fake([
            'localhost:8545*' => function (Request $request) {
                $body = json_decode($request->body(), true);

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $body['id'],
                    'error' => ['code' => -32601, 'message' => 'Method not found'],
                ]);
            },
        ]);

        ethService()->getBalance('0x'.str_repeat('a', 40));
    }

    public function test_envoie_une_transaction_avec_gas_et_gasprice_calcules(): void
    {
        Http::fake([
            'localhost:8545*' => ethRpc([
                'eth_gasPrice' => '0x3b9aca00',
                'eth_estimateGas' => '0x5208',
                'eth_sendTransaction' => '0x'.str_repeat('cd', 32),
            ]),
        ]);

        $service = ethService();
        $from = '0x'.str_repeat('aa', 20);
        $to = '0x'.str_repeat('bb', 20);

        $hash = $service->sendTransaction($from, $to, '0.1');

        $this->assertSame('0x'.str_repeat('cd', 32), $hash);

        $sent = Http::recorded()->first(fn ($pair) => data_get(json_decode($pair[0]->body(), true), 'method') === 'eth_sendTransaction');

        $this->assertNotNull($sent);

        $params = data_get(json_decode($sent[0]->body(), true), 'params.0');
        $this->assertSame($from, $params['from']);
        $this->assertSame($to, $params['to']);
        $this->assertSame(HexMath::decToHex(HexMath::etherToWei('0.1')), $params['value']);
        $this->assertSame('0x5208', $params['gas']);
        $this->assertSame(HexMath::decToHex('1100000000'), $params['gasPrice']);
    }

    public function test_trouve_une_transaction_native_sur_l_explorateur(): void
    {
        Http::fake([
            'api-sepolia.etherscan.io/*' => Http::response([
                'status' => '1',
                'message' => 'OK',
                'result' => [
                    [
                        'hash' => '0x'.str_repeat('ab', 32),
                        'to' => '0x'.str_repeat('bb', 20),
                        'isError' => '0',
                        'value' => '50000000000000000',
                    ],
                ],
            ]),
        ]);

        $service = ethService();
        $address = '0x'.str_repeat('bb', 20);

        $this->assertSame('0x'.str_repeat('ab', 32), $service->findTransactionForAddress($address, '0.05'));
    }

    public function test_ignore_une_transaction_en_erreur_ou_sous_le_montant_attendu(): void
    {
        Http::fake([
            'api-sepolia.etherscan.io/*' => Http::response([
                'status' => '1',
                'message' => 'OK',
                'result' => [
                    ['hash' => '0x'.str_repeat('01', 32), 'to' => '0x'.str_repeat('bb', 20), 'isError' => '1', 'value' => '9000000000000000000'],
                    ['hash' => '0x'.str_repeat('02', 32), 'to' => '0x'.str_repeat('bb', 20), 'isError' => '0', 'value' => '1000'],
                ],
            ]),
        ]);

        $service = ethService();
        $address = '0x'.str_repeat('bb', 20);

        $this->assertNull($service->findTransactionForAddress($address, '1'));
    }

    public function test_retourne_null_si_l_api_repond_vide(): void
    {
        Http::fake([
            'api-sepolia.etherscan.io/*' => Http::response(['status' => '0', 'message' => 'No transactions found', 'result' => []]),
        ]);

        $service = ethService();

        $this->assertNull($service->findTransactionForAddress('0x'.str_repeat('bb', 20), '1'));
    }

    public function test_leve_une_exception_si_l_explorateur_est_absent(): void
    {
        $this->expectException(BlockchainException::class);
        $this->expectExceptionMessage('Explorateur Ethereum non configuré');

        Http::fake();

        $service = ethService(['explorer_url' => null]);

        $service->findTransactionForAddress('0x'.str_repeat('bb', 20), '1');
    }

    public function test_rejette_un_hash_de_transaction_invalide(): void
    {
        $this->expectException(BlockchainException::class);

        Http::fake([
            'localhost:8545*' => ethRpc([]),
        ]);

        ethService()->getConfirmations('0xabc');
    }
}

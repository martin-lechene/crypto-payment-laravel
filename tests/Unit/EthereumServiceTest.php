<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;
use MartinLechene\CryptoPayments\Services\EthereumService;
use MartinLechene\CryptoPayments\Support\HexMath;

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

it('accepte et rejette les adresses Ethereum', function () {
    $service = ethService();

    expect($service->validateAddress('0x'.str_repeat('a', 40)))->toBeTrue();
    expect($service->validateAddress('0x'.str_repeat('A', 40)))->toBeTrue();
    expect($service->validateAddress(str_repeat('a', 40)))->toBeFalse();
    expect($service->validateAddress('0x'.str_repeat('a', 39)))->toBeFalse();
    expect($service->validateAddress('0x'.str_repeat('g', 40)))->toBeFalse();
});

it('génère une adresse via personal_newAccount', function () {
    Http::fake([
        'localhost:8545*' => ethRpc([
            'personal_newAccount' => '0x'.str_repeat('12ab', 10),
        ]),
    ]);

    $service = ethService();

    $account = $service->generateAddress();

    expect($account['address'])->toBe('0x'.str_repeat('12ab', 10));
});

it('retourne le solde en ether', function () {
    Http::fake([
        'localhost:8545*' => ethRpc([
            'eth_getBalance' => '0xde0b6b3a7640000',
        ]),
    ]);

    expect(ethService()->getBalance('0x'.str_repeat('a', 40)))->toBe('1');
});

it('retourne 0 confirmation sans receipt', function () {
    Http::fake([
        'localhost:8545*' => ethRpc([
            'eth_getTransactionReceipt' => null,
        ]),
    ]);

    $service = ethService();
    $hash = '0x'.str_repeat('ab', 32);

    expect($service->getConfirmations($hash))->toBe(0);
});

it('calcule les confirmations à partir du blockNumber', function () {
    Http::fake([
        'localhost:8545*' => ethRpc([
            'eth_getTransactionReceipt' => ['blockNumber' => '0x10'],
            'eth_blockNumber' => '0x14',
        ]),
    ]);

    $service = ethService();
    $hash = '0x'.str_repeat('ab', 32);

    expect($service->getConfirmations($hash))->toBe(4);
});

it('retourne le statut de transaction', function () {
    Http::fake([
        'localhost:8545*' => ethRpc([
            'eth_getTransactionReceipt' => ['status' => '0x1', 'blockNumber' => '0x10'],
        ]),
    ]);

    $service = ethService();
    $hash = '0x'.str_repeat('ab', 32);

    expect($service->getTransactionStatus($hash))->toBeTrue();
});

it('retourne le chain id du noeud', function () {
    Http::fake([
        'localhost:8545*' => ethRpc([
            'eth_chainId' => '0xaa36a7',
        ]),
    ]);

    expect(ethService()->getChainId())->toBe(11155111);
});

it('lève une exception BlockchainException si le rpc renvoie une erreur', function () {
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
})->throws(BlockchainException::class, 'Method not found');

it('envoie une transaction avec gas et gasPrice calculés', function () {
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

    expect($hash)->toBe('0x'.str_repeat('cd', 32));

    $sent = Http::recorded()->first(fn ($pair) => data_get(json_decode($pair[0]->body(), true), 'method') === 'eth_sendTransaction');

    expect($sent)->not->toBeNull();

    $params = data_get(json_decode($sent[0]->body(), true), 'params.0');
    expect($params['from'])->toBe($from);
    expect($params['to'])->toBe($to);
    expect($params['value'])->toBe(HexMath::decToHex(HexMath::etherToWei('0.1')));
    expect($params['gas'])->toBe('0x5208');
    expect($params['gasPrice'])->toBe(HexMath::decToHex('1100000000'));
});

it('trouve une transaction native sur l explorateur', function () {
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

    expect($service->findTransactionForAddress($address, '0.05'))->toBe('0x'.str_repeat('ab', 32));
});

it('ignore une transaction en erreur ou sous le montant attendu', function () {
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

    expect($service->findTransactionForAddress($address, '1'))->toBeNull();
});

it('retourne null si l API repond vide', function () {
    Http::fake([
        'api-sepolia.etherscan.io/*' => Http::response(['status' => '0', 'message' => 'No transactions found', 'result' => []]),
    ]);

    $service = ethService();

    expect($service->findTransactionForAddress('0x'.str_repeat('bb', 20), '1'))->toBeNull();
});

it('lève une exception si l explorateur est absent', function () {
    Http::fake();

    $service = ethService(['explorer_url' => null]);

    $service->findTransactionForAddress('0x'.str_repeat('bb', 20), '1');
})->throws(BlockchainException::class, 'Explorateur Ethereum non configuré');

it('rejette un hash de transaction invalide', function () {
    Http::fake([
        'localhost:8545*' => ethRpc([]),
    ]);

    ethService()->getConfirmations('0xabc');
})->throws(BlockchainException::class);

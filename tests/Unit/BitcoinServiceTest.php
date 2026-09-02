<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;
use MartinLechene\CryptoPayments\Services\BitcoinService;

function btcService(array $config = []): BitcoinService
{
    return new BitcoinService(array_merge([
        'enabled' => true,
        'rpc_url' => 'http://localhost:18332',
        'rpc_user' => 'bitcoin',
        'rpc_password' => 'password',
        'rpc_timeout' => 5,
        'verify_ssl' => true,
        'explorer_url' => 'https://blockstream.info/testnet/api',
    ], $config));
}

function btcRpc(array $byMethod): Closure
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

it('génère une adresse bech32 via le noeud', function () {
    Http::fake([
        'localhost:18332*' => btcRpc([
            'getnewaddress' => 'tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r',
        ]),
    ]);

    expect(btcService()->generateAddress())->toBe('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r');
});

it('lève une exception si le noeud retourne un id invalide', function () {
    Http::fake([
        'localhost:18332*' => btcRpc([
            'getnewaddress' => 'invalide!',
        ]),
    ]);

    btcService()->generateAddress();
})->throws(BlockchainException::class, 'Adresse invalide');

it('valide une adresse auprès du noeud', function () {
    Http::fake([
        'localhost:18332*' => btcRpc([
            'validateaddress' => ['isvalid' => true],
        ]),
    ]);

    $service = btcService();

    expect($service->validateAddress('3QJmV3qfvL9SuYo34YihAf3sRCW3qSinyC'))->toBeTrue();
});

it('rejette une adresse invalide validée par le noeud', function () {
    Http::fake([
        'localhost:18332*' => btcRpc([
            'validateaddress' => ['isvalid' => false],
        ]),
    ]);

    expect(btcService()->validateAddress('adresseinconnue'))->toBeFalse();
});

it('retourne le nombre de confirmations', function () {
    Http::fake([
        'localhost:18332*' => btcRpc([
            'getrawtransaction' => ['txid' => str_repeat('ab', 32), 'confirmations' => 6],
        ]),
    ]);

    expect(btcService()->getConfirmations(str_repeat('ab', 32)))->toBe(6);
});

it('retourne 0 confirmation sinon', function () {
    Http::fake([
        'localhost:18332*' => btcRpc([
            'getrawtransaction' => null,
        ]),
    ]);

    expect(btcService()->getConfirmations(str_repeat('ab', 32)))->toBe(0);
});

it('trouve une transaction correspondante sur l explorateur', function () {
    $address = 'tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r';

    Http::fake([
        'blockstream.info/*' => Http::response([
            [
                'txid' => str_repeat('ab', 32),
                'vout' => [
                    ['scriptpubkey_address' => $address, 'value' => 60000],
                ],
            ],
        ]),
    ]);

    $service = btcService();

    expect($service->findTransactionForAddress($address, 0.0005))->toBe(str_repeat('ab', 32));
});

it('ignore un output sous le montant attendu ou vers une autre adresse', function () {
    $address = 'tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r';

    Http::fake([
        'blockstream.info/*' => Http::response([
            [
                'txid' => str_repeat('cd', 32),
                'vout' => [
                    ['scriptpubkey_address' => 'tb1qautre', 'value' => 99999999],
                    ['scriptpubkey_address' => $address, 'value' => 1000],
                ],
            ],
        ]),
    ]);

    $service = btcService();

    expect($service->findTransactionForAddress($address, 0.001))->toBeNull();
});

it('retourne le solde via listunspent', function () {
    Http::fake([
        'localhost:18332*' => btcRpc([
            'listunspent' => [
                ['amount' => 0.5],
                ['amount' => 0.25],
            ],
        ]),
    ]);

    expect(btcService()->getBalance('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r'))->toBe(0.75);
});

it('lance une erreur blockchain si le rpc http échoue', function () {
    Http::fake([
        'localhost:18332*' => Http::response([], 500),
    ]);

    btcService()->getBalance('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r');
})->throws(BlockchainException::class, 'HTTP 500');

it('lève une exception si l explorateur est absent', function () {
    Http::fake();

    btcService(['explorer_url' => null])->findTransactionForAddress('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r', 0.001);
})->throws(BlockchainException::class, 'Explorateur Bitcoin non configuré');

<?php

namespace MartinLechene\CryptoPayments\Tests\Unit;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Exceptions\BlockchainException;
use MartinLechene\CryptoPayments\Services\BitcoinService;
use MartinLechene\CryptoPayments\Tests\TestCase;

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

class BitcoinServiceTest extends TestCase
{
    public function test_genere_une_adresse_bech32_via_le_noeud(): void
    {
        Http::fake([
            'localhost:18332*' => btcRpc([
                'getnewaddress' => 'tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r',
            ]),
        ]);

        $this->assertSame('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r', btcService()->generateAddress());
    }

    public function test_leve_une_exception_si_le_noeud_retourne_un_id_invalide(): void
    {
        $this->expectException(BlockchainException::class);
        $this->expectExceptionMessage('Adresse invalide');

        Http::fake([
            'localhost:18332*' => btcRpc([
                'getnewaddress' => 'invalide!',
            ]),
        ]);

        btcService()->generateAddress();
    }

    public function test_valide_une_adresse_aupres_du_noeud(): void
    {
        Http::fake([
            'localhost:18332*' => btcRpc([
                'validateaddress' => ['isvalid' => true],
            ]),
        ]);

        $service = btcService();

        $this->assertTrue($service->validateAddress('3QJmV3qfvL9SuYo34YihAf3sRCW3qSinyC'));
    }

    public function test_rejette_une_adresse_invalide_validee_par_le_noeud(): void
    {
        Http::fake([
            'localhost:18332*' => btcRpc([
                'validateaddress' => ['isvalid' => false],
            ]),
        ]);

        $this->assertFalse(btcService()->validateAddress('adresseinconnue'));
    }

    public function test_retourne_le_nombre_de_confirmations(): void
    {
        Http::fake([
            'localhost:18332*' => btcRpc([
                'getrawtransaction' => ['txid' => str_repeat('ab', 32), 'confirmations' => 6],
            ]),
        ]);

        $this->assertSame(6, btcService()->getConfirmations(str_repeat('ab', 32)));
    }

    public function test_retourne_0_confirmation_sinon(): void
    {
        Http::fake([
            'localhost:18332*' => btcRpc([
                'getrawtransaction' => null,
            ]),
        ]);

        $this->assertSame(0, btcService()->getConfirmations(str_repeat('ab', 32)));
    }

    public function test_trouve_une_transaction_correspondante_sur_l_explorateur(): void
    {
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

        $this->assertSame(str_repeat('ab', 32), $service->findTransactionForAddress($address, 0.0005));
    }

    public function test_ignore_un_output_sous_le_montant_attendu_ou_vers_une_autre_adresse(): void
    {
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

        $this->assertNull($service->findTransactionForAddress($address, 0.001));
    }

    public function test_retourne_le_solde_via_listunspent(): void
    {
        Http::fake([
            'localhost:18332*' => btcRpc([
                'listunspent' => [
                    ['amount' => 0.5],
                    ['amount' => 0.25],
                ],
            ]),
        ]);

        $this->assertSame(0.75, btcService()->getBalance('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r'));
    }

    public function test_lance_une_erreur_blockchain_si_le_rpc_http_echoue(): void
    {
        $this->expectException(BlockchainException::class);
        $this->expectExceptionMessage('HTTP 500');

        Http::fake([
            'localhost:18332*' => Http::response([], 500),
        ]);

        btcService()->getBalance('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r');
    }

    public function test_leve_une_exception_si_l_explorateur_est_absent(): void
    {
        $this->expectException(BlockchainException::class);
        $this->expectExceptionMessage('Explorateur Bitcoin non configuré');

        Http::fake();

        btcService(['explorer_url' => null])->findTransactionForAddress('tb1q3zpw3rfw9w2a0y6yqf3a8k3r6j7h4q0kzw3s9r', 0.001);
    }
}

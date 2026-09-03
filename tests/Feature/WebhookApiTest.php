<?php

namespace MartinLechene\CryptoPayments\Tests\Feature;

use Illuminate\Support\Facades\Http;
use MartinLechene\CryptoPayments\Models\PaymentWebhookEndpoint;
use MartinLechene\CryptoPayments\Models\WebhookEvent;
use MartinLechene\CryptoPayments\Tests\TestCase;

class WebhookApiTest extends TestCase
{
    public function test_cree_liste_met_a_jour_teste_et_supprime_un_endpoint_webhook_via_l_api(): void
    {
            Http::fake([
                'merchant.example/*' => Http::response(['ok' => true], 200),
            ]);

            $response = $this->postJson('/api/crypto-payments/webhooks', [
                'merchant_id' => 5,
                'url' => 'https://merchant.example/hook',
                'events' => ['payment_confirmed', 'payment_completed'],
                'description' => 'Boutique principale',
            ]);

            $response->assertStatus(201)->assertJsonPath('success', true);

            $data = $response->json('data');
        $this->assertSame('https://merchant.example/hook', $data['url']);
        $this->assertContains('payment_confirmed', $data['events']);
        $this->assertTrue($data['is_active']);
        $this->assertNotNull($data['secret']);

            $id = $data['id'];
        $this->assertNotNull(PaymentWebhookEndpoint::find($id));

            $this->getJson('/api/crypto-payments/webhooks?merchant_id=5')
                ->assertOk()
                ->assertJsonCount(1, 'data.data');

            $this->patchJson("/api/crypto-payments/webhooks/{$id}", [
                'url' => 'https://merchant.example/hook-v2',
                'is_active' => false,
            ])->assertOk();

            $endpoint = PaymentWebhookEndpoint::find($id);
        $this->assertSame('https://merchant.example/hook-v2', $endpoint->url);
        $this->assertFalse($endpoint->is_active);

            $this->postJson("/api/crypto-payments/webhooks/{$id}/test")->assertOk();

        $this->assertSame(1, WebhookEvent::where('event_type', 'test')->count());

            $this->deleteJson("/api/crypto-payments/webhooks/{$id}")->assertOk();
        $this->assertNull(PaymentWebhookEndpoint::find($id));
    }

    public function test_valide_les_donnees_de_creation_de_webhook(): void
    {
        $this->postJson('/api/crypto-payments/webhooks', [
            'merchant_id' => 1,
            'url' => 'pas-une-url',
            'events' => ['evenement_inconnu'],
        ])->assertStatus(422);
    }
}

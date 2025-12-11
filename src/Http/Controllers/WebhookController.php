<?php

namespace CreabyIA\CryptoPayments\Http\Controllers;

use CreabyIA\CryptoPayments\Models\PaymentWebhookEndpoint;
use CreabyIA\CryptoPayments\Models\WebhookEvent;
use CreabyIA\CryptoPayments\Http\Requests\CreateWebhookRequest;
use CreabyIA\CryptoPayments\Jobs\SendWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

class WebhookController
{
    public function create(CreateWebhookRequest $request): JsonResponse
    {
        $endpoint = PaymentWebhookEndpoint::create([
            'merchant_id' => $request->input('merchant_id'),
            'url' => $request->input('url'),
            'events' => $request->input('events'),
            'secret' => bin2hex(random_bytes(32)),
            'is_active' => true,
            'description' => $request->input('description'),
        ]);
        
        return response()->json([
            'success' => true,
            'data' => $endpoint,
        ], 201);
    }
    
    public function list(Request $request): JsonResponse
    {
        $endpoints = PaymentWebhookEndpoint::where('merchant_id', $request->input('merchant_id'))
            ->paginate($request->input('per_page', 20));
        
        return response()->json([
            'success' => true,
            'data' => $endpoints,
        ]);
    }
    
    public function update(PaymentWebhookEndpoint $endpoint, Request $request): JsonResponse
    {
        $endpoint->update($request->only(['url', 'events', 'description', 'is_active']));
        
        return response()->json([
            'success' => true,
            'data' => $endpoint,
        ]);
    }
    
    public function delete(PaymentWebhookEndpoint $endpoint): JsonResponse
    {
        $endpoint->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Endpoint supprimé',
        ]);
    }
    
    public function testEvent(PaymentWebhookEndpoint $endpoint): JsonResponse
    {
        // Dispatcher un test webhook
        Queue::push(
            new SendWebhookEvent(
                WebhookEvent::create([
                    'payment_id' => null,
                    'event_type' => 'test',
                    'webhook_url' => $endpoint->url,
                    'payload' => ['test' => true, 'timestamp' => now()->toIso8601String()],
                    'attempt' => 1,
                    'max_attempts' => 1,
                ]),
                $endpoint
            )
        );
        
        return response()->json([
            'success' => true,
            'message' => 'Test webhook envoyé',
        ]);
    }
}


<?php

namespace CreabyIA\CryptoPayments\Http\Controllers;

use CreabyIA\CryptoPayments\Models\CryptoPayment;
use CreabyIA\CryptoPayments\Services\PaymentManager;
use CreabyIA\CryptoPayments\Http\Requests\CreatePaymentRequest;
use CreabyIA\CryptoPayments\Http\Resources\PaymentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController
{
    public function __construct(protected PaymentManager $paymentManager) {}
    
    public function create(CreatePaymentRequest $request): JsonResponse
    {
        try {
            $payment = $this->paymentManager->createPaymentRequest(
                currency: $request->input('currency'),
                amountFiat: $request->input('amount'),
                fiatCurrency: $request->input('fiat_currency', 'USD'),
                options: $request->validated()
            );
            
            return response()->json([
                'success' => true,
                'data' => new PaymentResource($payment),
                'message' => 'Demande de paiement créée avec succès',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
    
    public function show(CryptoPayment $payment): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new PaymentResource($payment),
        ]);
    }
    
    public function checkStatus(CryptoPayment $payment): JsonResponse
    {
        $this->paymentManager->checkPaymentStatus($payment);
        
        return response()->json([
            'success' => true,
            'data' => new PaymentResource($payment->refresh()),
        ]);
    }
    
    public function list(Request $request): JsonResponse
    {
        $query = CryptoPayment::query();
        
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->input('merchant_id'));
        }
        
        if ($request->has('currency')) {
            $query->where('currency', strtoupper($request->input('currency')));
        }
        
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }
        
        if ($request->has('date_from') && $request->has('date_to')) {
            $query->byDate(
                $request->input('date_from'),
                $request->input('date_to')
            );
        }
        
        $payments = $query->paginate($request->input('per_page', 15));
        
        return response()->json([
            'success' => true,
            'data' => PaymentResource::collection($payments),
            'pagination' => [
                'total' => $payments->total(),
                'per_page' => $payments->perPage(),
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }
    
    public function refund(CryptoPayment $payment, Request $request): JsonResponse
    {
        try {
            $this->paymentManager->refundPayment(
                $payment,
                $request->input('reason')
            );
            
            return response()->json([
                'success' => true,
                'message' => 'Remboursement initié',
                'data' => new PaymentResource($payment->refresh()),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }
}


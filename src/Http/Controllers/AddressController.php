<?php

namespace CreabyIA\CryptoPayments\Http\Controllers;

use CreabyIA\CryptoPayments\Models\CryptoAddress;
use CreabyIA\CryptoPayments\Http\Resources\AddressResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController
{
    public function list(Request $request): JsonResponse
    {
        $query = CryptoAddress::query();
        
        if ($request->has('merchant_id')) {
            $query->where('merchant_id', $request->input('merchant_id'));
        }
        
        if ($request->has('currency')) {
            $query->byCurrency($request->input('currency'));
        }
        
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
        
        $addresses = $query->paginate($request->input('per_page', 50));
        
        return response()->json([
            'success' => true,
            'data' => AddressResource::collection($addresses),
        ]);
    }
    
    public function show(CryptoAddress $address): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new AddressResource($address),
        ]);
    }
    
    public function updateLabel(CryptoAddress $address, Request $request): JsonResponse
    {
        $address->update(['label' => $request->input('label')]);
        
        return response()->json([
            'success' => true,
            'data' => new AddressResource($address),
        ]);
    }
    
    public function deactivate(CryptoAddress $address): JsonResponse
    {
        $address->update(['is_active' => false]);
        
        return response()->json([
            'success' => true,
            'message' => 'Adresse désactivée',
        ]);
    }
}


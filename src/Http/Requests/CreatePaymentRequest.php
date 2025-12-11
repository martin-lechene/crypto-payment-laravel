<?php

namespace MartinLechene\CryptoPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'currency' => 'required|string|in:BTC,ETH',
            'amount' => 'required|numeric|min:0.01',
            'fiat_currency' => 'required|string|in:USD,EUR,GBP',
            'order_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'merchant_id' => 'nullable|integer',
            'description' => 'nullable|string|max:500',
            'expires_in_minutes' => 'nullable|integer|min:1|max:1440',
            'fee_percentage' => 'nullable|numeric|min:0|max:100',
            'metadata' => 'nullable|array',
            'payment_method' => 'nullable|string|in:native,contract,multisig',
        ];
    }
}


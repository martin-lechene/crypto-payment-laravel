<?php

namespace MartinLechene\CryptoPayments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateWebhookRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'merchant_id' => 'required|integer',
            'url' => 'required|url',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:payment_created,payment_confirming,payment_confirmed,payment_completed,payment_failed,payment_expired',
            'description' => 'nullable|string|max:500',
        ];
    }
}


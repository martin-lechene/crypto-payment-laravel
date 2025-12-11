<?php

namespace MartinLechene\CryptoPayments\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'address' => $this->address,
            'currency' => $this->currency,
            'label' => $this->label,
            'balance' => (string) $this->balance,
            'balance_updated_at' => $this->balance_updated_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'payment_count' => $this->payments()->count(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}


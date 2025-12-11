<?php

namespace MartinLechene\CryptoPayments\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'reference_code' => $this->reference_code,
            'currency' => $this->currency,
            'amount_crypto' => (string) $this->amount_crypto,
            'amount_fiat' => (string) $this->amount_fiat,
            'fiat_currency' => $this->fiat_currency,
            'wallet_address' => $this->wallet_address,
            'transaction_hash' => $this->transaction_hash,
            'status' => $this->status,
            'status_label' => $this->getAttribute('status') ? 
                \MartinLechene\CryptoPayments\Models\CryptoPayment::STATUSES[$this->status] ?? $this->status 
                : null,
            'confirmations' => $this->confirmations,
            'required_confirmations' => $this->required_confirmations,
            'exchange_rate' => (string) $this->exchange_rate,
            'network_fee' => $this->network_fee ? (string) $this->network_fee : null,
            'platform_fee' => (string) $this->platform_fee,
            'total_fee' => (string) $this->total_fee,
            'amount_received' => (string) $this->amount_received,
            'description' => $this->description,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'metadata' => $this->metadata,
        ];
    }
}


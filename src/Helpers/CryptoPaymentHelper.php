<?php

namespace MartinLechene\CryptoPayments\Helpers;

use MartinLechene\CryptoPayments\Models\CryptoPayment;

class CryptoPaymentHelper
{
    public static function getPaymentStatus(string $status): string
    {
        return CryptoPayment::STATUSES[$status] ?? $status;
    }
    
    public static function formatAmount(float $amount, int $decimals = 8): string
    {
        return number_format($amount, $decimals, '.', '');
    }
    
    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'confirming' => 'info',
            'confirmed' => 'success',
            'completed' => 'success',
            'expired' => 'danger',
            'failed' => 'danger',
            'refunded' => 'secondary',
            default => 'secondary',
        };
    }
}


<?php

namespace CreabyIA\CryptoPayments\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class EncryptionService
{
    public function encryptSensitive(string $data): string
    {
        if (!config('crypto-payments.encryption.enabled')) {
            return $data;
        }
        
        return Crypt::encryptString($data);
    }
    
    public function decryptSensitive(string $encrypted): string
    {
        if (!config('crypto-payments.encryption.enabled')) {
            return $encrypted;
        }
        
        try {
            return Crypt::decryptString($encrypted);
        } catch (\Exception $e) {
            Log::error('Erreur décryption: ' . $e->getMessage());
            return '';
        }
    }
}


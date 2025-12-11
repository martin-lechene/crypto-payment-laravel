<?php

namespace CreabyIA\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $table = 'exchange_rates';
    
    protected $fillable = [
        'crypto_currency',
        'fiat_currency',
        'rate',
        'source',
        'volume_24h',
        'market_cap',
        'price_change_24h',
    ];
    
    protected $casts = [
        'rate' => 'decimal:8',
        'volume_24h' => 'decimal:2',
        'market_cap' => 'decimal:2',
        'price_change_24h' => 'decimal:2',
    ];
    
    public function scopeLatest($query)
    {
        return $query->orderBy('updated_at', 'desc');
    }
    
    public function scopeFor($query, $crypto, $fiat)
    {
        return $query->where('crypto_currency', strtoupper($crypto))
                     ->where('fiat_currency', strtoupper($fiat));
    }
}


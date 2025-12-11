<?php

namespace CreabyIA\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CryptoAddress extends Model
{
    use SoftDeletes;
    
    protected $table = 'crypto_addresses';
    
    protected $fillable = [
        'merchant_id',
        'address',
        'currency',
        'label',
        'derivation_path',
        'public_key',
        'script_type',
        'balance',
        'balance_updated_at',
        'is_active',
        'metadata',
    ];
    
    protected $casts = [
        'balance' => 'decimal:8',
        'balance_updated_at' => 'datetime',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];
    
    public function payments(): HasMany
    {
        return $this->hasMany(CryptoPayment::class, 'wallet_address', 'address');
    }
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function scopeByCurrency($query, $currency)
    {
        return $query->where('currency', strtoupper($currency));
    }
}


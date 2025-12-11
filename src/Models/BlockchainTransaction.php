<?php

namespace CreabyIA\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlockchainTransaction extends Model
{
    protected $table = 'blockchain_transactions';
    
    protected $fillable = [
        'payment_id',
        'tx_hash',
        'block_hash',
        'block_number',
        'from_address',
        'to_address',
        'amount',
        'gas_used',
        'gas_price',
        'nonce',
        'input_data',
        'status',
        'confirmations',
        'confirmed_at',
        'raw_data',
    ];
    
    protected $casts = [
        'amount' => 'decimal:8',
        'gas_used' => 'integer',
        'gas_price' => 'decimal:8',
        'nonce' => 'integer',
        'confirmations' => 'integer',
        'confirmed_at' => 'datetime',
        'raw_data' => 'array',
    ];
    
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CryptoPayment::class, 'payment_id');
    }
    
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }
    
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}


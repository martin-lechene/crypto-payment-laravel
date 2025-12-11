<?php

namespace CreabyIA\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    
    protected $fillable = [
        'payment_id',
        'action',
        'changes',
        'user_id',
        'ip_address',
    ];
    
    protected $casts = [
        'changes' => 'array',
    ];
    
    const UPDATED_AT = null;
    
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CryptoPayment::class);
    }
}


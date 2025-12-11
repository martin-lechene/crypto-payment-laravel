<?php

namespace CreabyIA\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    protected $table = 'webhook_events';
    
    protected $fillable = [
        'payment_id',
        'event_type',
        'webhook_url',
        'payload',
        'response_code',
        'response_body',
        'attempt',
        'max_attempts',
        'next_retry_at',
        'completed_at',
        'failed_at',
    ];
    
    protected $casts = [
        'payload' => 'array',
        'response_body' => 'array',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
    
    const EVENT_TYPES = [
        'payment_created',
        'payment_confirming',
        'payment_confirmed',
        'payment_completed',
        'payment_failed',
        'payment_expired',
    ];
    
    public function payment(): BelongsTo
    {
        return $this->belongsTo(CryptoPayment::class);
    }
    
    public function scopePending($query)
    {
        return $query->whereNull('completed_at')->whereNull('failed_at');
    }
    
    public function scopeReadyForRetry($query)
    {
        return $query->where('next_retry_at', '<=', now())->pending();
    }
}


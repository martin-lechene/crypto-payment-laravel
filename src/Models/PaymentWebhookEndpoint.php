<?php

namespace MartinLechene\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentWebhookEndpoint extends Model
{
    use SoftDeletes;
    
    protected $table = 'payment_webhook_endpoints';
    
    protected $fillable = [
        'merchant_id',
        'url',
        'events',
        'secret',
        'is_active',
        'description',
        'last_triggered_at',
        'consecutive_failures',
    ];
    
    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    
    public function supportsEvent($eventType): bool
    {
        return in_array($eventType, $this->events ?? []);
    }
}


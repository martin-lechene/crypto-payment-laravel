<?php

namespace MartinLechene\CryptoPayments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CryptoPayment extends Model
{
    use SoftDeletes;
    
    protected $table = 'crypto_payments';
    
    protected $fillable = [
        'order_id',
        'user_id',
        'merchant_id',
        'currency',
        'amount_crypto',
        'amount_fiat',
        'fiat_currency',
        'wallet_address',
        'transaction_hash',
        'status',
        'confirmations',
        'required_confirmations',
        'exchange_rate',
        'network_fee',
        'platform_fee',
        'fee_percentage',
        'expires_at',
        'paid_at',
        'webhook_sent_at',
        'metadata',
        'description',
        'reference_code',
        'payment_method',
    ];
    
    protected $casts = [
        'amount_crypto' => 'decimal:8',
        'amount_fiat' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
        'network_fee' => 'decimal:8',
        'platform_fee' => 'decimal:2',
        'fee_percentage' => 'decimal:4',
        'confirmations' => 'integer',
        'required_confirmations' => 'integer',
        'expires_at' => 'datetime',
        'paid_at' => 'datetime',
        'webhook_sent_at' => 'datetime',
        'metadata' => 'array',
    ];
    
    // Statuts
    const STATUSES = [
        'pending' => 'En attente de paiement',
        'confirming' => 'Confirmations en cours',
        'confirmed' => 'Confirmé',
        'completed' => 'Complété',
        'expired' => 'Expiré',
        'failed' => 'Échoué',
        'refunded' => 'Remboursé',
    ];
    
    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMING = 'confirming';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_COMPLETED = 'completed';
    const STATUS_EXPIRED = 'expired';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';
    
    // Méthodes de paiement
    const PAYMENT_METHODS = ['native', 'contract', 'multisig'];
    
    public function transactions(): HasMany
    {
        return $this->hasMany(BlockchainTransaction::class, 'payment_id');
    }
    
    public function webhookEvents(): HasMany
    {
        return $this->hasMany(WebhookEvent::class, 'payment_id');
    }
    
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'payment_id');
    }
    
    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
    
    public function scopeConfirming($query)
    {
        return $query->where('status', self::STATUS_CONFIRMING);
    }
    
    public function scopeConfirmed($query)
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }
    
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }
    
    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }
    
    public function scopeByMerchant($query, $merchantId)
    {
        return $query->where('merchant_id', $merchantId);
    }
    
    public function scopeByDate($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }
    
    // Accesseurs et modificateurs
    public function getTotalFeeAttribute(): float
    {
        return (float) ($this->network_fee + $this->platform_fee);
    }
    
    public function getAmountReceivedAttribute(): float
    {
        return (float) ($this->amount_fiat - $this->total_fee);
    }
    
    // Méthodes métier
    public function isConfirmed(): bool
    {
        return $this->confirmations >= $this->required_confirmations;
    }
    
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
    
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
    
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'paid_at' => now(),
        ]);
        
        $this->logAudit('payment_completed', ['completed_at' => now()]);
    }
    
    public function markAsRefunded(): void
    {
        $this->update(['status' => self::STATUS_REFUNDED]);
        $this->logAudit('payment_refunded');
    }
    
    public function updateStatus(string $newStatus, array $data = []): void
    {
        $this->update(array_merge(['status' => $newStatus], $data));
        $this->logAudit('status_changed', ['new_status' => $newStatus]);
    }
    
    public function logAudit(string $action, array $changes = []): void
    {
        AuditLog::create([
            'payment_id' => $this->id,
            'action' => $action,
            'changes' => $changes,
            'user_id' => auth()->id(),
        ]);
    }
    
    public function generateReferenceCode(): string
    {
        return strtoupper(substr($this->currency, 0, 1) . '-' . 
                         str_pad($this->id, 8, '0', STR_PAD_LEFT) . '-' . 
                         substr(uniqid(), -4));
    }
    
    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->reference_code) {
                $model->reference_code = strtoupper(
                    uniqid(substr($model->currency ?? 'CRY', 0, 1))
                );
            }
        });
    }
}


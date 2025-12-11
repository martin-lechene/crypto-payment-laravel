<?php

namespace MartinLechene\CryptoPayments\Events;

use MartinLechene\CryptoPayments\Models\CryptoPayment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentConfirming
{
    use Dispatchable, InteractsWithSockets, SerializesModels;
    
    public function __construct(public CryptoPayment $payment) {}
}


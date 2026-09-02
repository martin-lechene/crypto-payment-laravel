<?php

namespace MartinLechene\CryptoPayments\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use MartinLechene\CryptoPayments\Models\CryptoPayment;

class PaymentCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CryptoPayment $payment) {}
}

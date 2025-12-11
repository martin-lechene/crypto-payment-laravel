<?php

namespace CreabyIA\CryptoPayments\Jobs;

use CreabyIA\CryptoPayments\Services\ExchangeRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RefreshExchangeRates implements ShouldQueue
{
    use Dispatchable, Queueable;
    
    public function handle(ExchangeRateService $service): void
    {
        $service->refreshAllRates();
    }
}


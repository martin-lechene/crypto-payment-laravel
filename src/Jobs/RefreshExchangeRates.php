<?php

namespace MartinLechene\CryptoPayments\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use MartinLechene\CryptoPayments\Services\ExchangeRateService;

class RefreshExchangeRates implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(ExchangeRateService $service): void
    {
        $service->refreshAllRates();
    }
}

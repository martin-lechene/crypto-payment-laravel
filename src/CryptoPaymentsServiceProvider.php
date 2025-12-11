<?php

namespace CreabyIA\CryptoPayments;

use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use CreabyIA\CryptoPayments\Services\{
    BitcoinService,
    EthereumService,
    PaymentManager,
    ExchangeRateService,
    WebhookManager,
    EncryptionService,
};
use CreabyIA\CryptoPayments\Jobs\{
    CheckPaymentConfirmations,
    RefreshExchangeRates,
};

class CryptoPaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/crypto-payments.php',
            'crypto-payments'
        );
        
        // Services
        $this->app->singleton(BitcoinService::class, function ($app) {
            $network = config('crypto-payments.default_network', 'testnet');
            $config = config("crypto-payments.networks.{$network}.bitcoin");
            return new BitcoinService($config);
        });
        
        $this->app->singleton(EthereumService::class, function ($app) {
            $network = config('crypto-payments.default_network', 'testnet');
            $config = config("crypto-payments.networks.{$network}.ethereum");
            return new EthereumService($config);
        });
        
        $this->app->singleton(ExchangeRateService::class, function ($app) {
            return new ExchangeRateService();
        });
        
        $this->app->singleton(WebhookManager::class, function ($app) {
            return new WebhookManager();
        });
        
        $this->app->singleton(EncryptionService::class, function ($app) {
            return new EncryptionService();
        });
        
        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager(
                $app->make(BitcoinService::class),
                $app->make(EthereumService::class),
                $app->make(ExchangeRateService::class),
                $app->make(WebhookManager::class),
                $app->make(EncryptionService::class)
            );
        });
    }
    
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        
        $this->publishes([
            __DIR__ . '/../config/crypto-payments.php' => config_path('crypto-payments.php'),
        ], 'crypto-payments-config');
        
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'crypto-payments-migrations');
        
        // Enregistrer les routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/crypto-payments.php');
        
        // Scheduler
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->job(CheckPaymentConfirmations::class)
                ->everyMinute()
                ->onOneServer();
            
            $schedule->job(RefreshExchangeRates::class)
                ->everyFiveMinutes();
        });
    }
}


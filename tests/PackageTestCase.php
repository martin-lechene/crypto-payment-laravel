<?php

namespace MartinLechene\CryptoPayments\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use MartinLechene\CryptoPayments\CryptoPaymentsServiceProvider;

abstract class PackageTestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CryptoPaymentsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWVA/ZoKZvp6fiiM10=');
    }
}

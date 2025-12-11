<?php

return [
    'default_network' => env('CRYPTO_NETWORK', 'testnet'),
    'default_currency' => env('CRYPTO_DEFAULT_CURRENCY', 'BTC'),
    
    'networks' => [
        'testnet' => [
            'bitcoin' => [
                'enabled' => true,
                'rpc_url' => env('BTC_TESTNET_RPC', 'http://localhost:18332'),
                'rpc_user' => env('BTC_TESTNET_USER', 'bitcoin'),
                'rpc_password' => env('BTC_TESTNET_PASSWORD', 'password'),
                'rpc_timeout' => 30,
                'address_derivation' => 'bip44', // bip44, bip49, bip84
                'master_seed' => env('BTC_MASTER_SEED'),
            ],
            'ethereum' => [
                'enabled' => true,
                'rpc_url' => env('ETH_TESTNET_RPC', 'http://localhost:8545'),
                'chain_id' => 5, // Goerli
                'rpc_timeout' => 30,
                'contract_abi' => [],
            ],
        ],
        'mainnet' => [
            'bitcoin' => [
                'enabled' => env('BTC_MAINNET_ENABLED', false),
                'rpc_url' => env('BTC_MAINNET_RPC'),
                'rpc_user' => env('BTC_MAINNET_USER'),
                'rpc_password' => env('BTC_MAINNET_PASSWORD'),
                'rpc_timeout' => 30,
            ],
            'ethereum' => [
                'enabled' => env('ETH_MAINNET_ENABLED', false),
                'rpc_url' => env('ETH_MAINNET_RPC'),
                'chain_id' => 1,
                'rpc_timeout' => 30,
            ],
        ],
    ],
    
    'confirmation_blocks' => [
        'bitcoin' => env('BTC_CONFIRMATIONS', 3),
        'ethereum' => env('ETH_CONFIRMATIONS', 12),
    ],
    
    'fees' => [
        'bitcoin' => [
            'slow' => 1,
            'standard' => 5,
            'fast' => 10,
            'custom' => true,
        ],
        'ethereum' => [
            'slow' => 20,
            'standard' => 50,
            'fast' => 100,
            'custom' => true,
        ],
    ],
    
    'payment_timeouts' => [
        'pending' => 15, // minutes
        'confirming' => 60, // minutes
    ],
    
    'exchange_rates' => [
        'provider' => env('EXCHANGE_RATES_PROVIDER', 'coingecko'),
        'coingecko_api_url' => 'https://api.coingecko.com/api/v3',
        'cache_duration' => 300, // 5 minutes
    ],
    
    'encryption' => [
        'enabled' => true,
        'algorithm' => 'AES-256-CBC',
    ],
    
    'webhooks' => [
        'enabled' => true,
        'timeout' => 30,
        'retry_attempts' => 3,
        'retry_delay' => 60,
    ],
    
    'monitoring' => [
        'enable_logs' => true,
        'enable_metrics' => true,
        'slack_webhook' => env('CRYPTO_SLACK_WEBHOOK'),
    ],
    
    'supported_currencies' => ['BTC', 'ETH'],
];


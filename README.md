# Crypto Payments for Laravel

<p align="center">
  <a href="https://github.com/martin-lechene/crypto-payment-laravel/actions/workflows/tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/martin-lechene/crypto-payment-laravel/tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/martin-lechene/crypto-payment-laravel?style=flat-square" alt="License"></a>
  <img src="https://img.shields.io/badge/PHP-8.2%20%7C%208.3%20%7C%208.4-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/Laravel-10%20%7C%2011%20%7C%2012-FF2D20?style=flat-square&logo=laravel" alt="Laravel">
  <img src="https://img.shields.io/badge/status-beta-orange?style=flat-square" alt="Beta">
</p>

> A Laravel package to accept **Bitcoin** and **Ethereum** payments with exchange-rate conversion, webhook notifications, and an audit trail.
>
> **Beta, not yet on Packagist.** Read [Known Limitations](#known-limitations) before using it with real funds.
>
> 🇫🇷 [Voir la documentation en français](#documentation-française)

---

## Table of Contents

- [Requirements](#requirements)
- [Features](#features)
- [Known Limitations](#known-limitations)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [REST API](#rest-api)
- [Webhooks](#webhooks)
- [Artisan Commands](#artisan-commands)
- [Scheduled Jobs](#scheduled-jobs)
- [Security](#security)
- [Testing](#testing)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License and contact](#license-and-contact)

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.2 |
| Laravel | ^10.0 \| ^11.0 \| ^12.0 |
| Bitcoin Core | RPC enabled (used for address generation and confirmations) |
| Ethereum node | JSON-RPC node (e.g. Geth) for confirmations; address generation uses `personal_newAccount`, which hosted providers such as Infura do not expose |
| Block explorers | An Esplora-compatible API (Bitcoin) and an Etherscan-compatible API (Ethereum) to detect incoming transactions |

---

## Features

- Bitcoin and Ethereum support (testnet and mainnet)
- One payment address per payment request
- Incoming transaction detection through the configured block explorer
- Blockchain confirmation tracking
- Fiat to crypto conversion via CoinGecko
- Webhooks signed with HMAC-SHA256, with timestamp and automatic retry (exponential backoff)
- Audit trail of payment status changes
- REST API for payments, addresses and webhook endpoints
- Scheduled jobs for automatic payment verification and exchange-rate refresh

---

## Known Limitations

> **Please read before using in production.** This package is in beta.

| Area | Status | Notes |
|---|---|---|
| REST routes | ⚠️ No authentication | The bundled `/api/crypto-payments/*` routes are registered **without any auth or authorization middleware**, and endpoints are not scoped to a merchant. Do not expose them publicly as-is: restrict them at the web-server level, or do not rely on them and call `PaymentManager` from your own authenticated controllers. |
| Webhook endpoint URLs | ⚠️ Not restricted | Any URL passing Laravel's `url` rule is accepted and called by your queue worker (no private-network / SSRF filtering). The signing secret is returned by the API in plain text. |
| Sensitive-data encryption | ⚠️ Helper only | `EncryptionService` wraps Laravel's `Crypt`, but the package does not encrypt stored columns with it automatically. |
| Transaction matching | ⚠️ Basic | Incoming payments are matched by address and by amount (>= expected) through the explorer; there is no partial-payment, over-payment or double-spend handling. |
| Ethereum | ⚠️ Node-managed keys | Addresses come from `personal_newAccount` and `sendTransaction()` uses `eth_sendTransaction`, so the node must manage and unlock the accounts. Private-key management is left to you. |
| BIP44 hierarchical addresses | ⚠️ Partial | Falls back to `getnewaddress`. Add [bitwasp/bitcoin](https://github.com/Bit-Wasp/bitcoin-php) for full BIP44/49/84 support. |
| Tests | ⚠️ No real node | 79 tests with faked HTTP calls. Integration tests against a real node are out of scope. |

---

## Installation

The package is **not published on Packagist yet**. Install it from the GitHub repository:

```bash
composer config repositories.crypto-payments vcs https://github.com/martin-lechene/crypto-payment-laravel
composer require martin-lechene/crypto-payments:dev-main
```

Once a stable tag exists, you will be able to use `composer require martin-lechene/crypto-payments`.

The service provider is auto-discovered. Publish the config and migrations, then migrate:

```bash
php artisan vendor:publish --tag=crypto-payments-config
php artisan vendor:publish --tag=crypto-payments-migrations
php artisan migrate
```

---

## Configuration

The config file is `config/crypto-payments.php`. Add the variables you need to your `.env` (a full list is in [`.env.example`](.env.example)):

| Variable | Default | Purpose |
|---|---|---|
| `CRYPTO_NETWORK` | `testnet` | `testnet` or `mainnet` |
| `CRYPTO_DEFAULT_CURRENCY` | `BTC` | Default currency |
| `BTC_TESTNET_RPC`, `BTC_TESTNET_USER`, `BTC_TESTNET_PASSWORD` | `http://localhost:18332`, `bitcoin`, `password` | Bitcoin testnet RPC |
| `BTC_TESTNET_VERIFY_SSL` | `true` | TLS verification of the RPC endpoint |
| `BTC_TESTNET_EXPLORER` | Blockstream testnet API | Explorer used to detect incoming transactions |
| `BTC_MAINNET_ENABLED`, `BTC_MAINNET_RPC`, `BTC_MAINNET_USER`, `BTC_MAINNET_PASSWORD`, `BTC_MAINNET_VERIFY_SSL`, `BTC_MAINNET_EXPLORER` | disabled | Bitcoin mainnet |
| `BTC_CONFIRMATIONS` | `3` | Confirmations required |
| `ETH_TESTNET_RPC`, `ETH_TESTNET_VERIFY_SSL`, `ETH_TESTNET_EXPLORER`, `ETH_TESTNET_EXPLORER_KEY` | `http://localhost:8545` (Sepolia, chain id 11155111) | Ethereum testnet |
| `ETH_MAINNET_ENABLED`, `ETH_MAINNET_RPC`, `ETH_MAINNET_VERIFY_SSL`, `ETH_MAINNET_EXPLORER`, `ETH_MAINNET_EXPLORER_KEY` | disabled | Ethereum mainnet |
| `ETH_CONFIRMATIONS` | `12` | Confirmations required |
| `EXCHANGE_RATES_PROVIDER` | `coingecko` | Exchange-rate provider |
| `CRYPTO_SLACK_WEBHOOK` | empty | Optional monitoring webhook |

Other keys live only in the config file (publish it to change them): `fees`, `payment_timeouts` (pending 15 min, confirming 60 min), `exchange_rates.cache_duration`, `webhooks` (`enabled`, `timeout`, `retry_attempts`, `retry_delay`), `monitoring`, `supported_currencies`.

> ⚠️ **Never disable `*_VERIFY_SSL` on mainnet nodes.** Keep RPC credentials in your environment, never in version control.

---

## Usage

### Create a payment request

```php
use MartinLechene\CryptoPayments\Services\PaymentManager;

$payment = app(PaymentManager::class)->createPaymentRequest(
    currency: 'BTC',
    amountFiat: 100.00,
    fiatCurrency: 'USD',
    options: [
        'merchant_id' => 1,
        'order_id'    => 123,
        'description' => 'Order #123',
    ]
);

// $payment->wallet_address  -> send crypto here
// $payment->amount_crypto   -> amount expected
// $payment->expires_at      -> payment deadline
```

Other supported `options`: `user_id`, `fee_percentage`, `expires_in_minutes`, `metadata` (array), `payment_method`.

### Check payment status

```php
app(PaymentManager::class)->checkPaymentStatus($payment);
```

Other `PaymentManager` methods: `completePayment($payment)`, `refundPayment($payment, $reason)`.

---

## REST API

All routes are prefixed with `/api/crypto-payments` (see the [authentication warning](#known-limitations)).

| Method | Path | Purpose |
|---|---|---|
| POST | `/payments` | Create a payment (`currency`, `amount`, `fiat_currency` required) |
| GET | `/payments` | List payments (`merchant_id`, `currency`, `status`, `date_from`, `date_to`, `per_page`) |
| GET | `/payments/{payment}` | Show a payment |
| GET | `/payments/{payment}/status` | Refresh and return the status |
| POST | `/payments/{payment}/refund` | Mark a payment as refunded |
| GET | `/addresses` | List addresses (`merchant_id`, `currency`, `active`) |
| GET | `/addresses/{address}` | Show an address |
| PATCH | `/addresses/{address}/label` | Update an address label |
| POST | `/addresses/{address}/deactivate` | Deactivate an address |
| POST | `/webhooks` | Register a webhook endpoint |
| GET | `/webhooks` | List endpoints (`merchant_id`) |
| PATCH | `/webhooks/{endpoint}` | Update an endpoint |
| DELETE | `/webhooks/{endpoint}` | Delete an endpoint |
| POST | `/webhooks/{endpoint}/test` | Send a test event |

```http
POST /api/crypto-payments/payments
Content-Type: application/json

{
  "currency": "BTC",
  "amount": 100,
  "fiat_currency": "USD",
  "merchant_id": 1,
  "description": "Order #123"
}
```

`fiat_currency` accepts `USD`, `EUR` or `GBP`.

---

## Webhooks

### Register an endpoint

```http
POST /api/crypto-payments/webhooks
Content-Type: application/json

{
  "merchant_id": 1,
  "url": "https://example.com/webhooks/crypto",
  "events": ["payment_completed", "payment_confirmed"],
  "description": "Main webhook"
}
```

The response contains a generated `secret` used to sign deliveries.

### Verify the signature

Each delivery carries three headers: `X-Webhook-Signature` (`sha256=<hmac>`), `X-Webhook-Timestamp` (Unix time) and `X-Webhook-Event`. The HMAC-SHA256 is computed over `"{timestamp}.{raw JSON body}"` with the endpoint secret. Verify it on the **raw request body** and reject stale timestamps:

```php
$timestamp = $request->header('X-Webhook-Timestamp');
$signature = $request->header('X-Webhook-Signature');
$expected  = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $yourSecret);

if (! hash_equals($expected, (string) $signature) || abs(time() - (int) $timestamp) > 300) {
    abort(401, 'Invalid signature');
}
```

### Available events

| Event | Trigger |
|---|---|
| `payment_created` | A new payment request is created |
| `payment_confirming` | First blockchain confirmation received |
| `payment_confirmed` | Required confirmations reached |
| `payment_completed` | Payment marked as complete |
| `payment_expired` | Payment window expired |

`payment_failed` is accepted when registering an endpoint, and `test` is sent by the test route.

---

## Artisan Commands

```bash
# Generate payment addresses (BTC or ETH) for a merchant
php artisan crypto:generate-addresses BTC --count=10 --merchant-id=1
```

---

## Scheduled Jobs

The service provider registers these jobs on Laravel's scheduler, so you only need the usual `schedule:run` cron entry:

| Job | Schedule |
|---|---|
| `CheckPaymentConfirmations` | Every minute (one server) |
| `RefreshExchangeRates` | Every 5 minutes |

Webhook deliveries are queued, so run a queue worker:

```bash
php artisan queue:work
```

---

## Security

| Measure | Detail |
|---|---|
| Webhook signing | HMAC-SHA256 over `timestamp.body` (`X-Webhook-Signature`, `X-Webhook-Timestamp`) |
| Address validation | Regex / RPC validation |
| TLS verification | Enabled by default for RPC calls; configurable per network |
| Encryption helper | `EncryptionService` (Laravel `Crypt`), not applied automatically |
| Audit trail | Payment status changes are logged |

See the [Known Limitations](#known-limitations) for what is **not** covered yet. To report a vulnerability, follow [SECURITY.md](SECURITY.md) (contact@doganddev.eu); do not open a public issue.

---

## Project Structure

```
src/
├── Console/Commands/   # Artisan commands
├── Events/             # Domain events
├── Exceptions/         # Custom exceptions
├── Helpers/            # Utility helpers
├── Http/
│   ├── Controllers/    # API controllers
│   ├── Requests/       # Form request validation
│   └── Resources/      # API resources (transformers)
├── Jobs/               # Queued jobs
├── Models/             # Eloquent models
├── Services/           # Core business logic
└── Support/            # Math helpers
```

---

## Testing

```bash
composer install
composer test          # PHPUnit, in-memory SQLite, no node required
composer format-check  # Laravel Pint
```

The suite (79 tests) covers the configuration, Bitcoin and Ethereum services (RPC and explorer calls faked), `HexMath`, the payment manager, the REST API, webhook delivery and signing, and the service provider. GitHub Actions runs it on PHP 8.2 to 8.4 against Laravel 10, 11 and 12.

---

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md).

## License and contact

MIT, see [LICENSE](LICENSE). Contact: contact@doganddev.eu

---

---

# Documentation Française

> 🇬🇧 [English documentation above](#crypto-payments-for-laravel)

**Bêta, pas encore sur Packagist.** Lisez les limitations avant toute utilisation avec de vrais fonds.

## Prérequis

| Dépendance | Version |
|---|---|
| PHP | ^8.2 |
| Laravel | ^10.0 \| ^11.0 \| ^12.0 |
| Bitcoin Core | RPC activé |
| Nœud Ethereum | Nœud JSON-RPC (ex. Geth) ; la génération d'adresses utilise `personal_newAccount` |
| Explorateurs | API compatible Esplora (Bitcoin) et Etherscan (Ethereum) pour détecter les transactions entrantes |

## Fonctionnalités

- Support Bitcoin et Ethereum (testnet et mainnet)
- Une adresse par demande de paiement
- Détection des transactions entrantes via l'explorateur configuré
- Suivi des confirmations blockchain
- Conversion fiat vers crypto via CoinGecko
- Webhooks signés (HMAC-SHA256 avec horodatage) avec retry automatique
- Historique d'audit des changements de statut
- API REST (paiements, adresses, endpoints de webhook)

## Limitations connues

> **À lire avant une mise en production.**

- Les routes REST `/api/crypto-payments/*` sont enregistrées **sans authentification** ni cloisonnement par marchand : ne les exposez pas telles quelles.
- Les URL de webhook ne sont pas filtrées (pas de protection SSRF) et le secret de signature est renvoyé en clair par l'API.
- `EncryptionService` est un simple utilitaire : il ne chiffre pas automatiquement les colonnes stockées.
- La correspondance des paiements est basique (adresse et montant) : pas de gestion des paiements partiels ou excédentaires.
- Ethereum : les clés sont gérées par le nœud (`personal_newAccount`, `eth_sendTransaction`).
- Adresses BIP44 : repli sur `getnewaddress`.
- Tests : 79 tests avec appels HTTP simulés, pas de test contre un vrai nœud.

## Installation

Le package n'est pas encore publié sur Packagist :

```bash
composer config repositories.crypto-payments vcs https://github.com/martin-lechene/crypto-payment-laravel
composer require martin-lechene/crypto-payments:dev-main
php artisan vendor:publish --tag=crypto-payments-config
php artisan vendor:publish --tag=crypto-payments-migrations
php artisan migrate
```

## Configuration

Voir le tableau des variables dans la section Configuration ci-dessus et le fichier [`.env.example`](.env.example).

> ⚠️ **Ne désactivez jamais `*_VERIFY_SSL` sur un nœud mainnet.**

## Utilisation

```php
use MartinLechene\CryptoPayments\Services\PaymentManager;

$payment = app(PaymentManager::class)->createPaymentRequest(
    currency: 'BTC',
    amountFiat: 100.00,
    fiatCurrency: 'EUR',
    options: [
        'merchant_id' => 1,
        'order_id'    => 123,
        'description' => 'Commande #123',
    ]
);

app(PaymentManager::class)->checkPaymentStatus($payment);
```

Vérification de signature des webhooks : voir « Verify the signature » ci-dessus (HMAC-SHA256 sur `timestamp.corps_brut`).

## Support

Pour une question ou un bug, ouvrez une [issue GitHub](https://github.com/martin-lechene/crypto-payment-laravel/issues). Pour une faille de sécurité : [SECURITY.md](SECURITY.md) ou contact@doganddev.eu.

# Crypto Payments - Package Laravel Complet

Package complet pour gérer les paiements en Bitcoin et Ethereum dans vos applications Laravel.

## 📋 Fonctionnalités

- ✅ Support Bitcoin et Ethereum
- ✅ Génération d'adresses de paiement
- ✅ Suivi des confirmations blockchain
- ✅ Gestion des taux de change (CoinGecko)
- ✅ Système de webhooks avec retry
- ✅ Chiffrement des données sensibles
- ✅ Audit trail complet
- ✅ API REST complète
- ✅ Jobs planifiés pour la vérification automatique

## 🚀 Installation

### Via Composer

```bash
composer require martin-lechene/crypto-payments
```

### Configuration

Publier la configuration :

```bash
php artisan vendor:publish --tag=crypto-payments-config
```

Publier les migrations :

```bash
php artisan vendor:publish --tag=crypto-payments-migrations
```

### Variables d'environnement

Ajouter dans votre fichier `.env` :

```env
# Network
CRYPTO_NETWORK=testnet
CRYPTO_DEFAULT_CURRENCY=BTC

# Bitcoin Testnet
BTC_TESTNET_RPC=http://localhost:18332
BTC_TESTNET_USER=bitcoin
BTC_TESTNET_PASSWORD=password
BTC_CONFIRMATIONS=3

# Ethereum Testnet
ETH_TESTNET_RPC=http://localhost:8545
ETH_CONFIRMATIONS=12

# Exchange Rates
EXCHANGE_RATES_PROVIDER=coingecko
```

### Migrations

Exécuter les migrations :

```bash
php artisan migrate
```

## 📖 Utilisation

### Créer un paiement

```php
use MartinLechene\CryptoPayments\Services\PaymentManager;

$paymentManager = app(PaymentManager::class);

$payment = $paymentManager->createPaymentRequest(
    currency: 'BTC',
    amountFiat: 100.00,
    fiatCurrency: 'USD',
    options: [
        'merchant_id' => 1,
        'order_id' => 123,
        'description' => 'Paiement commande #123',
    ]
);
```

### Vérifier le statut d'un paiement

```php
$paymentManager->checkPaymentStatus($payment);
```

### API REST

#### Créer un paiement

```http
POST /api/crypto-payments/payments
Content-Type: application/json

{
  "currency": "BTC",
  "amount": 100,
  "fiat_currency": "USD",
  "merchant_id": 1,
  "description": "Paiement commande #123"
}
```

#### Vérifier le statut

```http
GET /api/crypto-payments/payments/{id}/status
```

#### Lister les paiements

```http
GET /api/crypto-payments/payments?merchant_id=1&status=pending
```

### Webhooks

#### Créer un endpoint webhook

```http
POST /api/crypto-payments/webhooks
Content-Type: application/json

{
  "merchant_id": 1,
  "url": "https://example.com/webhook",
  "events": ["payment_completed", "payment_confirmed"],
  "description": "Webhook principal"
}
```

Les webhooks sont signés avec HMAC-SHA256. Vérifier la signature :

```php
$signature = $request->header('X-Webhook-Signature');
$payload = $request->all();
$secret = 'your-webhook-secret';

$expectedSignature = 'sha256=' . hash_hmac('sha256', json_encode($payload), $secret);

if (!hash_equals($expectedSignature, $signature)) {
    abort(401, 'Invalid signature');
}
```

## 🔧 Commandes Artisan

### Générer des adresses

```bash
php artisan crypto:generate-addresses BTC --count=10 --merchant-id=1
```

## 📊 Jobs Planifiés

Le package inclut des jobs planifiés automatiquement :

- **CheckPaymentConfirmations** : Vérifie les confirmations chaque minute
- **RefreshExchangeRates** : Actualise les taux de change toutes les 5 minutes

## 🔐 Sécurité

- ✅ Chiffrement AES-256 pour les données sensibles
- ✅ Webhooks signés avec HMAC-SHA256
- ✅ Validation stricte des adresses
- ✅ Audit trail complet
- ✅ Rate limiting recommandé sur les endpoints

## 📝 Structure

```
src/
├── Models/              # Modèles Eloquent
├── Services/            # Services métier
├── Http/
│   ├── Controllers/     # Contrôleurs API
│   ├── Requests/        # Form Requests
│   └── Resources/       # API Resources
├── Jobs/                # Jobs queue
├── Events/              # Events
├── Exceptions/          # Exceptions personnalisées
├── Console/Commands/     # Commandes Artisan
└── Helpers/             # Helpers
```

## 🧪 Tests

```bash
phpunit
```

## 📄 License

MIT

## 🤝 Support

Pour toute question ou problème, ouvrez une issue sur GitHub.

## 🔄 Changelog

Voir [CHANGELOG.md](CHANGELOG.md) pour la liste des changements.


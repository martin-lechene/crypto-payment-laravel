# Contributing

Thanks for your interest in improving `martin-lechene/crypto-payments`.
This package is in beta: bug reports, tests and documentation fixes are very welcome.

## Security issues

Do **not** open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).

## Development setup

```bash
git clone https://github.com/martin-lechene/crypto-payment-laravel.git
cd crypto-payment-laravel
composer install
composer test
```

The tests use [Orchestra Testbench](https://packages.tools/testbench) with an in-memory
SQLite database. No Bitcoin or Ethereum node is needed: RPC and explorer calls are faked.

## Pull requests

1. Fork the repository and create a branch from `main` (`feat/...`, `fix/...`, `docs/...`).
2. Add or update tests for any behavior change.
3. Run `composer test` and `composer format-check` (Laravel Pint); fix style with `composer format`.
4. Update `CHANGELOG.md` under `[Unreleased]`.
5. Use [Conventional Commits](https://www.conventionalcommits.org/) for commit messages (`fix:`, `feat:`, `docs:`, `chore:`...).
6. Open the PR against `main` and fill in the template.

## Supported versions

PHP 8.2+ and Laravel 10, 11 or 12.

## Code of conduct

By participating you agree to abide by the [Code of Conduct](CODE_OF_CONDUCT.md).

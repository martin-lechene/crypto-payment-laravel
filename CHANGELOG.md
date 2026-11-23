# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `LICENSE` (MIT), `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, issue and pull request templates.
- GitHub Actions workflow: tests on PHP 8.2 to 8.4 against Laravel 10, 11 and 12, plus Laravel Pint.
- Dependabot configuration (composer and github-actions), `.gitattributes` with `export-ignore` rules.
- Laravel Pint configuration and `format` / `format-check` composer scripts.

### Changed
- README rewritten: beta status, VCS installation (not on Packagist yet), full configuration table, REST route table, corrected webhook signature example, up-to-date known limitations.
- `composer.json`: keywords, support links, homepage, contact email, English description; PHPUnit constraint widened to `^10.5|^11.5|^12.5` so that Laravel 10 and 11 can be tested; removed the forced `config.platform.php` and `minimum-stability: dev`.
- `.env.example`: explorer and `*_VERIFY_SSL` variables documented.

### Fixed
- `guzzlehttp/guzzle` is now a declared dependency: the package uses Laravel's HTTP client (`Http::`), which needs it, but it was missing from `composer.json`.

### Removed
- Unused `suggest` entry for `sc0vu/web3.php` (`dev-master`): the package does not use it.
- Unused `BTC_MASTER_SEED` entry from `.env.example`: no code reads it.

## [0.1.0] - 2025-12-11 (initial development, never tagged)

### Added
- Bitcoin and Ethereum support (testnet and mainnet).
- Payment address generation and blockchain confirmation tracking.
- Exchange rates through CoinGecko.
- Webhooks with retry and HMAC-SHA256 signature.
- Audit trail, REST API, scheduled jobs and Artisan command to generate addresses.

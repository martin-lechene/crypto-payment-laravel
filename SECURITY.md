# Security Policy

This package handles payment flows (Bitcoin / Ethereum), webhook signing and
node credentials. It is currently in **beta**: please read the
[Known Limitations](README.md#known-limitations) and the
[Security notes](README.md#security) before using it in production.

## Supported versions

| Version | Supported |
|---|---|
| Latest release (or `main` before the first tag) | Yes |
| Older releases | No |

## Reporting a vulnerability

**Please do not open a public issue for security problems.**

- Use GitHub private reporting: **Security > Report a vulnerability** on
  [this repository](https://github.com/martin-lechene/crypto-payment-laravel/security/advisories/new), or
- email **contact@doganddev.eu** with the subject `[SECURITY] crypto-payments`.

Please include the affected version or commit, a description of the impact, and
steps to reproduce (a minimal proof of concept helps). Never include real private
keys, seeds, RPC credentials or webhook secrets in a report.

## What to expect

- Acknowledgement within 5 working days.
- An assessment and, if confirmed, a fix and a coordinated disclosure date.
- Credit in the changelog if you wish.

This is a small open-source project without a bug bounty programme.

## Scope

In scope: webhook signature handling, authorization gaps in the bundled REST
routes, SSRF or injection issues, handling of secrets (RPC credentials, webhook
secrets, encrypted data), payment-state logic errors.

Out of scope: vulnerabilities in your Bitcoin / Ethereum node, in third-party
explorers or in your own application code.

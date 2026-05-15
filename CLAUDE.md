# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Common commands

```bash
composer install
composer test                            # vendor/bin/pest
composer test:coverage
composer format                          # PHP-CS-Fixer fix
composer lint                            # PHP-CS-Fixer dry-run + diff
composer stan                            # PHPStan analyse

vendor/bin/pest tests/WebhooksTest.php   # run one file
vendor/bin/pest --filter='verifies a valid signature'  # run one test by name
```

PHP 8.2+ is required. The dev toolchain (Pest, PHPStan, PHP-CS-Fixer) is pulled in via Composer — there's no global tooling.

## Architecture

This SDK is intentionally **thin**: it forwards JSON, adds auth + idempotency headers, decodes responses, and maps non-2xx into typed exceptions. No retries, no caching, no client-side validation. Callers control retry policy.

### Request pipeline (`src/VorgioClient.php`)

`VorgioClient::request()` is the single chokepoint every resource call funnels through. It:

1. Lowercases caller headers, then merges them over a fixed set (`authorization`, `accept`, `user-agent`).
2. For `POST`/`PATCH`/`PUT`/`DELETE`, auto-generates a UUIDv7 `Idempotency-Key` unless the caller supplied one. UUIDv7 is chosen because the 48-bit ms-prefix makes keys time-sortable (`src/Util/Uuid.php`).
3. Builds the URL via `buildUrl()` — paths default to `/api/{apiVersion}{path}`, but a caller-supplied path starting with `/api/` is passed through unchanged.
4. Catches `ConnectException` → `VorgioException`; otherwise reads the response and runs `parseResponse()`.
5. `parseResponse()` maps status to exceptions: `422` → `VorgioValidationException` (carries `errors` field map), `429` → `VorgioRateLimitedException` (carries `Retry-After` seconds, default 60), other 4xx/5xx → `VorgioApiException`. All exceptions carry the RFC 7807 problem document, raw body, and `X-Request-Id`.

### Resources (`src/Resource/*`)

Each resource (`Checkouts`, `Invoices`, `Clients`) extends `AbstractResource`, which holds the `VorgioClient` and exposes a protected `request()` proxy. Resource methods are one-liners that build a path + body + optional `Idempotency-Key` header and delegate. Adding a new endpoint means: add a method to the relevant resource (or a new `AbstractResource` subclass + accessor on `VorgioClient`).

### Webhooks (`src/Webhooks.php`)

Stateless utility, Stripe-style API (`Webhooks::constructEvent`). Signature scheme **must** stay byte-compatible with the server (`app/Jobs/DispatchWebhook.php` in the API repo): HMAC-SHA256 over `<unix_ts>.<raw_body>`, header format `t=<ts>,v1=<hex>`, 5-minute default replay window. Comparisons use `hash_equals`. The injectable `$now` parameter exists so tests can pin time without mocking.

### Laravel integration (`src/Laravel/`)

Auto-discovered via `composer.json` → `extra.laravel`. The service provider registers `VorgioClient` as a singleton from `config('vorgio.*')` and aliases `'vorgio'`. The Laravel directory is **excluded from PHPUnit coverage** (`phpunit.xml.dist`) and the SDK works without Laravel installed — the provider is loaded but never invoked outside a Laravel app.

### Tests (`tests/`)

Pest, with `tests/Pest.php` providing two helpers used everywhere:

- `vorgioMockClient(array $responses)` — builds a `VorgioClient` backed by Guzzle's `MockHandler`. Returns `[client, $history]`; `$history` is an `ArrayObject` (not array) so the Guzzle history middleware's writes remain visible through list destructuring.
- `jsonResponse()` / `problemResponse()` — quick builders for 2xx JSON and RFC 7807 problem responses.

When adding tests, prefer asserting against `$history` for request shape (method, URL, headers, JSON body) rather than mocking `VorgioClient` itself.

## Conventions

- `declare(strict_types=1);` at the top of every PHP file.
- Public surface uses named arguments in docs/examples (`new VorgioClient(token: …, baseUrl: …)`). Keep constructor params readonly + named-friendly.
- All return types from `VorgioClient::request()` and resources are `array<string, mixed>` — decoded JSON. Do not introduce typed DTOs without discussing the trade-off first.
- `VorgioClient::VERSION` is sent in the `User-Agent` and must be bumped on release.

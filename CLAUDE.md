# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Common commands

```bash
composer install
composer test                            # vendor/bin/pest
composer test:coverage
composer format                          # PHP-CS-Fixer fix
composer lint                            # PHP-CS-Fixer dry-run + diff
composer stan                            # PHPStan analyse --memory-limit=512M

vendor/bin/pest tests/WebhooksTest.php   # run one file
vendor/bin/pest --filter='verifies a valid signature'  # run one test by name
```

PHP 8.2+ is required. The dev toolchain (Pest, PHPStan, PHP-CS-Fixer, Orchestra Testbench) is pulled in via Composer — there's no global tooling.

## Architecture

This SDK is intentionally **thin**: it forwards JSON, attaches auth + idempotency headers, decodes responses, and maps non-2xx into typed exceptions. There is one narrow retry loop (transport errors + 5xx, see `RetryPolicy`) — beyond that, no caching and no client-side validation. Callers stay in control.

### Request pipeline (`src/VorgioClient.php`)

`VorgioClient::request()` is the chokepoint every resource call funnels through. It:

1. Lowercases caller headers, then merges them over a fixed set (`authorization`, `accept`, `user-agent`).
2. For `POST`/`PATCH`/`PUT`/`DELETE`, auto-generates a UUIDv7 `Idempotency-Key` unless the caller already supplied one. UUIDv7 is chosen because the 48-bit ms-prefix makes keys time-sortable (`src/Util/Uuid.php`). The key is computed **once, outside** the retry loop, so every attempt sends the same key — that's what lets the server's middleware replay the cached 2xx.
3. Builds the URL via `buildUrl()` — paths default to `/api/{apiVersion}{path}`, but a caller-supplied path starting with `/api/` is passed through unchanged.
4. Dispatches through `dispatchWithRetry()`, which consults `RetryPolicy` for transport-level (`ConnectException`) and 5xx responses. 4xx — including 422 (validation) and 429 (rate-limit) — is **never** retried.
5. `throwForResponse()` maps status to exceptions: `422` → `VorgioValidationException` (carries `errors` field map), `429` → `VorgioRateLimitedException` (carries `Retry-After` seconds, default 60), other 4xx/5xx → `VorgioApiException`. All exceptions carry the RFC 7807 problem document, raw body, and `X-Request-Id`.

`VorgioClient::requestRaw()` is the sibling entry point for binary/non-JSON endpoints (e.g. `GET /v1/invoices/{id}/pdf`, see `src/InvoicePdf.php`). It returns `['status', 'headers', 'body']` and treats 304 as a non-error so ETag flows work.

### Resources (`src/Resource/*`)

Each resource (`Checkouts`, `Invoices`, `Clients`, `Subscriptions`) extends `AbstractResource`, which holds the `VorgioClient` and exposes a protected `request()` proxy plus an `idempotencyHeader($operationId, $purpose)` helper.

POST methods on resources accept an optional **`operationId`** (UUIDv7) — *not* a raw `idempotencyKey`. `AbstractResource::idempotencyHeader()` derives the actual `Idempotency-Key` via `OperationDerivation` so chained POSTs under one operation (e.g. `stop` + `cancel` during subscription teardown) get distinct keys via different `$purpose` tags but stay deterministically replayable. When `operationId` is `null`, `VorgioClient` auto-generates a one-shot UUIDv7 per call.

Adding a new endpoint: add a method to the relevant resource (or a new `AbstractResource` subclass + accessor on `VorgioClient`). Pass `$purpose` strings that are unique within the operation, e.g. `'subscription.start'`.

### Support utilities (`src/Support/`)

- **`OperationDerivation`** — deterministic functions of one caller-persisted UUIDv7 (`operationId`) that yield: per-purpose `Idempotency-Key`s, per-position UUIDs for line items, and a snapshot timestamp from the UUIDv7's embedded ms-prefix. Same `operationId` + same purpose → byte-identical output forever. This is what makes long-running, queue-retried operations safe.
- **`RetryPolicy`** — small backoff schedule (default `[200, 800, 3200]` ms). `RetryPolicy::disabled()` returns a no-op policy used by tests and one-shot callers. Retries fire on transport-level failures and 5xx only.

### Webhooks (`src/Webhooks.php`)

Stateless utility, Stripe-style API (`Webhooks::constructEvent`). Signature scheme **must** stay byte-compatible with the server (`app/Jobs/DispatchWebhook.php` in the API repo): HMAC-SHA256 over `<unix_ts>.<raw_body>`, header format `t=<ts>,v1=<hex>`, 5-minute default replay window. Comparisons use `hash_equals`. The injectable `$now` parameter exists so tests can pin time without mocking.

### Laravel integration (`src/Laravel/`)

Auto-discovered via `composer.json` → `extra.laravel`. The provider registers `VorgioClient` as a singleton from `config('vorgio.*')` and aliases `'vorgio'`. The Laravel directory is **excluded from PHPUnit coverage** (`phpunit.xml.dist`); the SDK works without Laravel installed.

The Laravel layer is a Cashier-style integration:

- **`Billable` trait** (`src/Laravel/Billable.php`) — add to a paying-customer model (e.g. `User`). Exposes `subscribe()`, `changeBillingCycle()`, `cancelSubscription()`, `createAsVorgioCustomer()`, and `vorgioBillable` / `vorgioSubscription(s)` / `vorgioInvoices` relations.
- **`runOperation()` state machine** — the core of retry-safety. Each public Billable method find-or-creates a row in `vorgio_operations` (purpose + billable scoped, locked with `lockForUpdate`) carrying a persistent UUIDv7 `operation_id`. That id seeds every `Idempotency-Key` derivation. A queue retry that re-enters the method picks up the same row → same id → same key → server replays the cached 2xx instead of duplicating the side effect. 4xx marks the operation `failed` so retries stop; transport errors leave it `pending` for the queue worker to retry.
- **`WebhookController`** (`src/Laravel/Http/WebhookController.php`) — verifies the signature, upserts the local `Invoice` / `Subscription` / `VorgioBillable` mirror tables, and dispatches typed events (`VorgioInvoiceSent`, `VorgioInvoicePaid`, `VorgioInvoiceCancelled`, `VorgioSubscriptionStarted`, `VorgioSubscriptionStopped`, `VorgioBillingCycleChanged`, `VorgioCustomerCreated`). Unknown billables are skipped silently (handles webhook-before-local-write races; Vorgio will redeliver).
- Migrations live under `src/Laravel/database/migrations/`.

### Tests (`tests/`)

Pest, with `tests/Pest.php` providing helpers used everywhere:

- `vorgioMockClient(array $responses)` — builds a `VorgioClient` backed by Guzzle's `MockHandler`. Returns `[client, $history]`; `$history` is an `ArrayObject` (not array) so the Guzzle history middleware's writes remain visible through list destructuring. The client is constructed with `RetryPolicy::disabled()` by default so a single mocked response is enough — pass an explicit policy to exercise the retry loop.
- `jsonResponse()` / `problemResponse()` — quick builders for 2xx JSON and RFC 7807 problem responses.

When adding tests, prefer asserting against `$history` for request shape (method, URL, headers, JSON body) rather than mocking `VorgioClient` itself. Laravel-side tests live under `tests/Laravel/` and use Orchestra Testbench.

## Conventions

- `declare(strict_types=1);` at the top of every PHP file.
- Public surface uses named arguments in docs/examples (`new VorgioClient(token: …, baseUrl: …)`). Keep constructor params readonly + named-friendly.
- POST/PATCH methods on resources take `operationId: ?string` (not `idempotencyKey:`) — the SDK derives the actual header. Don't reintroduce raw-key parameters.
- All return types from `VorgioClient::request()` and resources are `array<string, mixed>` — decoded JSON. Do not introduce typed DTOs without discussing the trade-off first.
- `VorgioClient::VERSION` is sent in the `User-Agent` and must be bumped on release (also update `CHANGELOG.md`).

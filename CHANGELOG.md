# Changelog

All notable changes to `vorgio-app/vorgio-php` are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] — 2026-05-16

The headline change is the Cashier-style Laravel layer. The core SDK
remains framework-agnostic; Laravel consumers get a `Billable` trait and
Vorgio-owned mirror tables that absorb the correctness primitives every
integrator was reinventing by hand.

### Added

- **`Resource\Subscriptions`** — new resource with `start()`,
  `changeCycle()`, `stop()` methods backed by the matching V endpoints.
  Accessed via `$vorgio->subscriptions()`.
- **`Invoices::cancel($id, $operationId = null)`** — issues a
  Stornorechnung (UStG §14c / GoBD-compliant legal cancellation).
- **`Support\OperationDerivation`** — single source of truth for deriving
  per-purpose `Idempotency-Key` headers, deterministic position UUIDs,
  and snapshot timestamps from one caller-supplied UUIDv7. Replaces
  hand-rolled key strings in every previously-known integrator.
- **`Util\Uuid::v5()` and `Util\Uuid::extractTimestampMs()`** — UUIDv5
  derivation and UUIDv7 timestamp extraction. `Uuid::NAMESPACE_VORGIO_OP`
  is the locked-in namespace for all operation derivations.
- **Internal retry** — the SDK now retries up to 3 times on transport
  failures and 5xx responses, replaying the same `Idempotency-Key` and
  body each attempt. Configurable via `new VorgioClient(retry: ...)`.
  Never retries 4xx; 429 keeps its existing `Retry-After`-aware path.
- **Cashier-style Laravel layer** (`src/Laravel/`):
  - `Vorgio\Laravel\Billable` trait — drop into any Eloquent model to
    gain `subscribe()`, `changeBillingCycle()`, `cancelSubscription()`,
    `createAsVorgioCustomer()`, and the relations.
  - Four auto-loaded migrations creating polymorphic Vorgio-owned tables
    (`vorgio_billables`, `vorgio_subscriptions`, `vorgio_operations`,
    `vorgio_invoices`). No columns ever added to the consumer's domain
    tables.
  - Webhook controller registered at `config('vorgio.webhook.route')`
    that verifies signatures, upserts the mirror rows, and dispatches
    typed Laravel events.
  - Typed events: `VorgioCustomerCreated`, `VorgioSubscriptionStarted`,
    `VorgioSubscriptionStopped`, `VorgioBillingCycleChanged`,
    `VorgioInvoiceSent`, `VorgioInvoicePaid`, `VorgioInvoiceCancelled`.
  - Publish groups `vorgio-config` and `vorgio-migrations`.
- **Examples** — `examples/recurring-billing.php` (framework-agnostic)
  and `examples/cashier-style.php` (Laravel trait flow).

### Changed

- **BREAKING:** `?string $idempotencyKey` parameter renamed to
  `?string $operationId` on `Invoices::create`, `Invoices::send`,
  `Invoices::markPaid`, `Clients::create`, and `Checkouts::create`. The
  new parameter must be a UUIDv7; the SDK derives per-purpose
  `Idempotency-Key` headers from it via `OperationDerivation`. Callers
  that need to set an `Idempotency-Key` directly can still pass one
  through the low-level `VorgioClient::request()` headers argument.
- **BREAKING:** `VorgioClient` constructor accepts an optional
  `RetryPolicy $retry` parameter. Default behaviour is now *retry on*
  (`enabled: true`, 3 attempts).
- `VorgioClient::VERSION` bumped to `0.2.0`.

### Migration guide (from 0.1.x)

Mechanical rename:

```diff
- $vorgio->checkouts()->create($payload, idempotencyKey: $key);
+ $vorgio->checkouts()->create($payload, operationId: $opId);
```

`$opId` must be a UUIDv7 string (the SDK now derives a per-purpose
`Idempotency-Key` from it, instead of forwarding an opaque string).
Generate one via `Vorgio\Util\Uuid::v7()` and persist it before the
first call so retries replay the same value:

```php
use Vorgio\Util\Uuid;

$opId = $order->get_meta('_vorgio_operation_id');
if ($opId === '') {
    $opId = Uuid::v7();
    $order->update_meta_data('_vorgio_operation_id', $opId);
    $order->save();
}

$vorgio->checkouts()->create($payload, operationId: $opId);
```

**WooCommerce plugin consumers:** `vorgio-for-woocommerce` ≤ 0.1.x
constructs idempotency keys as `'wc-order-{id}-{key}'` — non-UUIDv7
strings. v0.2.0 of this SDK will reject those at runtime. Coordinate
the plugin's release so it derives a UUIDv7 from order meta (pattern
above) before bumping its SDK constraint to `^0.2.0`.

Recurring-billing consumers should switch from the bespoke
`invoices()->create() + invoices()->send() + invoices()->delete()` dance
to either `subscriptions()->start() / ->changeCycle() / ->stop()` (core
SDK) or the new `Vorgio\Laravel\Billable` trait (Laravel apps).

## [0.1.0] — 2026-04-09

Initial public release.

[0.2.0]: https://github.com/vorgio-app/vorgio-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/vorgio-app/vorgio-php/releases/tag/v0.1.0

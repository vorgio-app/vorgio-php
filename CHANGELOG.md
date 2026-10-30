# Changelog

All notable changes to `vorgio-app/vorgio-php` are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.2] — 2026-10-30

### Fixed

- A redelivered `invoice.paid` webhook no longer overwrites the mirror's
  `paid_at`: the first value is kept, also when the payload has none (it used
  to fall back to the current time).
- `invoice.paid` no longer flips a cancelled mirror back to `paid`: the status
  and `cancelled_at` stay, and `paid_at` is only filled if it was empty.
  `VorgioInvoicePaid` still dispatches in both cases, so consumers decide what
  a repeated or late payment means.

## [0.4.1] — 2026-06-02

### Documentation

- The README and `examples/` now note that `client.language` is **optional**:
  when omitted, the Vorgio API defaults the client to the authenticated team's
  invoice locale. No SDK behavior change — the wrapper still forwards whatever
  client payload it's given, and an explicit `language` continues to override.

## [0.4.0] — 2026-06-02

Minor release. Makes the **connection doctor** fully localizable. Every check
`label` and `detail` now resolves through Laravel's translation system under
the `vorgio::doctor.*` namespace instead of hardcoded English, so a consuming
app renders the connection panel in its own locale. Backwards compatible — the
report shape is unchanged and `label`/`detail` are still ready-to-render
strings, just localized to `app()->getLocale()`.

### Added

- **Translations for the connection doctor** shipped for English, German,
  French, and Spanish under `src/Laravel/lang/{en,de,fr,es}/doctor.php`.
- **Publishable translations** via `php artisan vendor:publish --tag=vorgio-lang`
  (lands in `lang/vendor/vorgio/`). Apps can override any string or drop in a
  new `lang/vendor/vorgio/<locale>/doctor.php` to add a language — Laravel's
  loader resolves it automatically.

### Changed

- `Vorgio\Laravel\ConnectionDoctor` no longer hardcodes English check text; it
  looks strings up via the `vorgio::doctor.*` namespace. The
  `VorgioServiceProvider` now calls `loadTranslationsFrom(__DIR__.'/lang',
  'vorgio')`. The `vorgio:doctor` command's own framing lines remain English.

## [0.3.0] — 2026-06-02

Minor release. Adds a **connection doctor** so an integration can verify it is
wired up correctly without guessing — which team + mode a token is bound to,
whether it carries the required abilities, and whether a webhook is actually
registered and reachable. Requires vorgio.app to expose the `GET
/api/v1/connection` and `POST /api/v1/connection/test-webhook` endpoints
(shipped alongside this release). Backwards compatible — existing callers are
unaffected.

### Added

- **`$vorgio->connection()` resource** with `retrieve()` (introspect the
  current team, mode, token abilities, billing readiness, and registered
  webhooks) and `sendTestWebhook()` (ask Vorgio to deliver a signed
  `connection.test` event to your endpoint, proving the shared secret + URL).
- **`Vorgio\Laravel\ConnectionDoctor`** — a service that runs the checks and
  returns a structured `{ok, connection, checks[]}` report, suitable for both
  CLI output and rendering in an operator GUI. The side-effecting webhook
  round-trip is opt-in via `run(includeWebhookTest: true)`.
- **`php artisan vorgio:doctor`** — prints the checklist; `--test-webhook`
  also fires the live test event. Exit code is non-zero when any check fails,
  so it doubles as a deploy/healthcheck gate.
- **Config:** `vorgio.required_abilities` and `vorgio.required_webhook_events`
  let you tune what the doctor considers "correctly set up".

## [0.2.3] — 2026-06-01

Patch release. Fixes a metadata round-trip gap in the Laravel `Billable`
trait, found while preparing the first production (yearly) subscription. No
caller-facing API has changed; existing callers upgrade transparently.

### Fixed

- **`Billable::subscribe()` now forwards a `metadata` key nested in the
  invoice payload.** `/v1/checkouts` reads `metadata` at the **top level**
  and echoes it on every webhook payload. Callers that passed `metadata`
  inside `$invoicePayload` (the natural spot, alongside `subject` /
  `positions`) had it dropped by the server's request validation, so the
  value never reached the invoice or its webhooks. `subscribe()` now lifts a
  `metadata` key out of the invoice payload to the checkout top level.
  Callers already passing top-level metadata are unaffected.

## [0.2.2] — 2026-05-17

Follow-up patch release. v0.2.1's `vorgio_operations` composite index
exceeded MySQL's 3072-byte combined-key cap under utf8mb4 — every
consumer running migrations against MySQL would hit `SQLSTATE[42000]:
1071 Specified key was too long`. SQLite (the SDK's own test driver)
doesn't enforce that limit, which is why the v0.2.1 suite passed and the
regression slipped through. v0.2.2 caps the string columns used in the
composite indexes to lengths that fit the budget.

### Fixed

- **`vorgio_operations` composite index now fits MySQL utf8mb4.** Column
  lengths capped: `billable_type` 191, `billable_id` 64, `purpose` 32,
  `status` 16. Combined index footprint drops from 4 080 bytes to
  ~1 212 bytes.
- **`vorgio_billables`** — `billable_type` 191, `billable_id` 64.
- **`vorgio_subscriptions`** — `every` 32, `status` 16.
- **`vorgio_invoices`** — `status` 16.

No data migration is required: consumers who failed to migrate at
v0.2.1 against MySQL can drop the new constraint by re-running
`php artisan migrate` after upgrading.

## [0.2.1] — 2026-05-17

Patch release. Fixes two Laravel-layer issues uncovered while wiring v0.2.0
into MVGV. No caller-facing API has changed; consumers on bigint-keyed
billables can upgrade transparently.

### Fixed

- **Polymorphic `billable_id` is now a string column.** Previously declared
  as `unsignedBigInteger` on `vorgio_billables` and `vorgio_operations`,
  which silently truncated UUIDs to `0` and broke every polymorphic
  lookup for consumers that key their billable model with `HasUuids`
  (e.g. MVGV's `Association`). The column now stores whatever key shape
  the consumer model uses. `VorgioBillable::$casts['billable_id'] =>
  'integer'` was dropped accordingly.

### Added

- **`vorgio_invoices` mirror persists more of the webhook payload.** Five
  additional columns — `number`, `billing_date`, `every`,
  `next_invoice_at`, `metadata` — populated by the webhook controller
  from the inbound `invoice` object. Consumer UIs that previously needed
  to round-trip to the API for the invoice number / billing date can
  now read them from the mirror.

### Tests

- Cashier-style test fixture (`TestAssociation` + `test_associations`)
  switched to UUID PKs so the full suite exercises the polymorphic-id
  path the same way MVGV does in production. Pin against future
  regressions of the v0.2.0 bigint trap.

### Upgrade notes

Drop the new dependency, run `php artisan migrate` — no further action
needed. No data migration is required: any rows previously written by
v0.2.0 against a UUID-keyed billable would have a `billable_id` of `0`
(broken anyway), so the schema change loses nothing.

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

[0.2.2]: https://github.com/vorgio-app/vorgio-php/releases/tag/v0.2.2
[0.2.1]: https://github.com/vorgio-app/vorgio-php/releases/tag/v0.2.1
[0.2.0]: https://github.com/vorgio-app/vorgio-php/releases/tag/v0.2.0
[0.1.0]: https://github.com/vorgio-app/vorgio-php/releases/tag/v0.1.0

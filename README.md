# vorgio-php

Official PHP SDK for the [Vorgio](https://vorgio.app) invoicing API.

Thin, framework-agnostic wrapper. Optional Laravel auto-discovery (service
provider + facade + publishable config) loads automatically when Laravel is
installed; in vanilla PHP / WordPress the SDK works without it.

- **PHP 8.2+**
- **Zero retries / no caching** — callers control retry policy explicitly
- **HMAC-SHA256 webhook verification** with replay protection
- **Auto-generated UUIDv7 idempotency keys** when you don't supply one

## Installation

```bash
composer require vorgio-app/vorgio-php
```

## Quick start

```php
use Vorgio\VorgioClient;

$vorgio = new VorgioClient(
    token: 'act_…',                       // from Vorgio → Team → API tokens
    baseUrl: 'https://vorgio.app',
);

$result = $vorgio->checkouts()->create([
    'client' => [
        'external_id' => 'wc_customer_42',
        'name'        => 'Jane Customer',
        'email'       => 'jane@example.com',
        'address'     => 'Musterstraße 1',
        'zip'         => '10115',
        'city'        => 'Berlin',
        'country'     => 'DE',
        'language'    => 'de',
        'rate'        => 0,
        'vat'         => 19,
        'default_position_mode' => 'fixed',
    ],
    'invoice' => [
        'tax_rate'         => 19,
        'due_offset_days'  => 14,
        'positions'        => [
            [
                'id'           => '0193f7b0-1b8a-7b7d-9ad0-0c7b5b1d5f3e',
                'date'         => '2026-05-10',
                'mode'         => 'fixed',
                'description'  => 'One unit of widget',
                'amount_cents' => 9900,
            ],
        ],
    ],
    'send' => [
        'subject' => 'Your invoice from Demo Shop',
        'body'    => "Hello,\n\nplease find your invoice attached.",
    ],
    'metadata' => ['order_id' => 'wc_order_999'],   // round-tripped on every webhook
]);

echo $result['data']['invoice']['number'];   // INV-2026-0042
```

See [`examples/checkout.php`](examples/checkout.php) for a runnable script.

## Resources

| Surface                                | Method                                      |
|----------------------------------------|---------------------------------------------|
| `POST /v1/checkouts`                   | `$vorgio->checkouts()->create($body)`       |
| `GET  /v1/invoices`                    | `$vorgio->invoices()->list($query)`         |
| `POST /v1/invoices`                    | `$vorgio->invoices()->create($body)`        |
| `GET  /v1/invoices/{id}`               | `$vorgio->invoices()->retrieve($id)`        |
| `PATCH /v1/invoices/{id}`              | `$vorgio->invoices()->update($id, $body)`   |
| `DELETE /v1/invoices/{id}`             | `$vorgio->invoices()->delete($id)`          |
| `POST /v1/invoices/{id}/send`          | `$vorgio->invoices()->send($id, $body)`     |
| `POST /v1/invoices/{id}/mark-paid`     | `$vorgio->invoices()->markPaid($id, $body)` |
| `GET  /v1/invoices/{id}/pdf`           | `$vorgio->invoices()->pdf($id)`             |
| `GET  /v1/clients`                     | `$vorgio->clients()->list($query)`          |
| `POST /v1/clients`                     | `$vorgio->clients()->create($body)`         |
| `GET  /v1/clients/{id}`                | `$vorgio->clients()->retrieve($id)`         |
| `PATCH /v1/clients/{id}`               | `$vorgio->clients()->update($id, $body)`    |
| `DELETE /v1/clients/{id}`              | `$vorgio->clients()->delete($id)`           |

Every method returns the decoded JSON response as a PHP `array`. The full
contract for each request/response shape lives at
[vorgio.app/api-reference](https://vorgio.app/api-reference).

## Idempotency

`POST` / `PATCH` / `PUT` / `DELETE` requests automatically receive a UUIDv7
`Idempotency-Key` header when you don't provide one — that's the right
default for genuinely new one-shot requests.

For multi-step flows (a queued job, a checkout that fires three sequential
POSTs, anything that retries) pass an `operationId` instead. The SDK then
derives a stable, per-purpose `Idempotency-Key` from your operation id —
the same operation id produces the same key every time, so queue retries
and midnight-cross retries replay the server's cached 2xx instead of
generating a fresh side-effect.

```php
use Vorgio\Util\Uuid;

$opId = Uuid::v7();           // persist this before the call
$vorgio->checkouts()->create($body, operationId: $opId);

// Later, on retry — same op id, same Idempotency-Key, same cached response:
$vorgio->checkouts()->create($body, operationId: $opId);
```

If you ship the same idempotency key twice within the server's retention
window, you get back the original response with an
`Idempotency-Replay: true` header.

### Internal retry

By default the SDK retries up to three times on transport errors and 5xx
responses (with exponential backoff: 200ms, 800ms, 3200ms), replaying the
same `Idempotency-Key` and body on each attempt. 4xx responses are never
retried; 429 keeps its caller-driven `Retry-After`-aware flow. Disable per
client when you'd rather handle retries yourself:

```php
use Vorgio\Support\RetryPolicy;

$vorgio = new VorgioClient(token: '…', retry: RetryPolicy::disabled());
```

## Recurring billing

Use `subscriptions()` for any flow with an `every` cadence — membership
billing, SaaS, anything that issues invoices on a schedule. The resource
maps cleanly onto Vorgio's recurring-template verbs:

```php
use Vorgio\Util\Uuid;

$opId = Uuid::v7();

// Provision the client + recurring template + send the first invoice
// in one call. Powered by /v1/checkouts under the hood.
$started = $vorgio->subscriptions()->start([
    'client'  => [/* … */],
    'every'   => 'monthly',
    'invoice' => [
        'subject'   => 'Mitgliedsbeitrag',
        'tax_rate'  => 19,
        'positions' => [['mode' => 'fixed', 'amount_cents' => 9900]],
    ],
], operationId: $opId);

$templateId = $started['data']['invoice']['id'];

// Change cadence in place — no new invoice issued, just the template's
// `every` and `next_invoice_at` updated.
$vorgio->subscriptions()->changeCycle($templateId, 'yearly', operationId: $opId);

// Stop future generation without deleting the template (full audit
// trail stays visible in the operator dashboard).
$vorgio->subscriptions()->stop($templateId, operationId: $opId);

// Issue a Stornorechnung for a finalised invoice (UStG §14c-compliant
// legal cancellation). Use this for the open child invoice, not the
// recurring template itself.
$vorgio->invoices()->cancel('inv_abc', operationId: $opId);
```

See `examples/recurring-billing.php` for an end-to-end smoke flow.

## Downloading PDFs

```php
$pdf = $vorgio->invoices()->pdf($invoiceId);

file_put_contents("/tmp/{$invoiceId}.pdf", $pdf->bytes);
$cachedEtag = $pdf->etag;   // stash this alongside your record

// Later — pass the stored ETag to short-circuit a re-render:
$pdf = $vorgio->invoices()->pdf($invoiceId, ifNoneMatch: $cachedEtag);

if ($pdf->notModified) {
    // 304 — your cached copy is still current.
} else {
    // PDF changed — overwrite local copy and update $cachedEtag = $pdf->etag.
}
```

The returned `Vorgio\InvoicePdf` is a tiny value object with `bytes`,
`etag`, and `notModified`. The ETag value is opaque — store and replay it
verbatim, including the surrounding quotes.

## Errors

```php
use Vorgio\Exception\VorgioApiException;
use Vorgio\Exception\VorgioRateLimitedException;
use Vorgio\Exception\VorgioValidationException;

try {
    $vorgio->checkouts()->create($body);
} catch (VorgioValidationException $e) {
    // 422 — $e->errors is the field => messages map
} catch (VorgioRateLimitedException $e) {
    // 429 — $e->retryAfter is the seconds you should wait
} catch (VorgioApiException $e) {
    // any other 4xx / 5xx — $e->statusCode, $e->problem (RFC 7807),
    // $e->requestId for log correlation
}
```

The SDK never retries on its own; pick a strategy that fits your runtime
(queue back-off, sync retry with jitter, etc.).

## Verifying webhooks

```php
use Vorgio\Webhooks;
use Vorgio\Exception\VorgioSignatureException;

try {
    $event = Webhooks::constructEvent(
        payload:   file_get_contents('php://input'),
        sigHeader: $_SERVER['HTTP_VORGIO_SIGNATURE'] ?? '',
        secret:    getenv('VORGIO_WEBHOOK_SECRET'),
    );
} catch (VorgioSignatureException $e) {
    http_response_code(400);
    return;
}

if ($event->type === 'invoice.paid') {
    // …fulfil the order
}
```

The signature scheme matches the server byte-for-byte: HMAC-SHA256 over
`<unix_ts>.<raw_body>`, with a 5-minute default replay tolerance.

See [`examples/webhook.php`](examples/webhook.php).

## Laravel

When the package is installed inside a Laravel app it auto-registers a
service provider. Publish the config and set the env vars:

```bash
php artisan vendor:publish --tag=vorgio-config
```

```dotenv
VORGIO_TOKEN=act_…
VORGIO_BASE_URL=https://vorgio.app
VORGIO_WEBHOOK_SECRET=whsec_…
```

Then resolve the client wherever you need it:

```php
use Vorgio\Laravel\Facades\Vorgio;

Vorgio::checkouts()->create([...]);
```

…or inject `Vorgio\VorgioClient` via the container.

### Cashier-style recurring billing

For Laravel apps doing recurring billing — SaaS, membership dues, anything
on a cadence — the package ships a Cashier-style `Billable` trait that
absorbs every correctness primitive (idempotency keys, queue-retry safety,
midnight-cross timestamping, customer/subscription persistence) into the
package. **No columns are ever added to your domain tables.** All Vorgio
state lives in package-owned polymorphic tables:
`vorgio_billables`, `vorgio_subscriptions`, `vorgio_operations`,
`vorgio_invoices`.

The migrations are auto-loaded by the service provider. Run them once and
add the trait to the model that represents your paying customer:

```bash
php artisan migrate
```

```php
use Vorgio\Laravel\Billable;

class Association extends Model
{
    use Billable;
}
```

Then the model gains:

```php
// Start a recurring subscription. Idempotent: a second call returns the
// existing subscription without hitting the API.
$subscription = $association->subscribe('monthly', [
    'subject'   => 'Mitgliedsbeitrag',
    'tax_rate'  => 19,
    'positions' => [['mode' => 'fixed', 'amount_cents' => 9900]],
]);

// Change cadence in place.
$association->changeBillingCycle('yearly');

// End the arrangement. Pick the child-invoice strategy that matches
// your product's "cancel my subscription" semantics:
//   'stop-only'        — stop future invoices only.
//   'storno-always'    — also Storno the open child invoice.
//   'storno-if-unpaid' — Storno only when the child is unpaid (SaaS default).
$association->cancelSubscription('storno-if-unpaid');

// Helpers + relations:
$association->hasActiveSubscription();
$association->latestOpenInvoiceId();
$association->vorgioSubscriptions;     // HasManyThrough → Subscription
$association->vorgioInvoices;          // HasManyThrough → Invoice (webhook mirror)
```

Set `VORGIO_WEBHOOK_SECRET` in `.env` and the package registers
`POST /vorgio/webhook` for you. It verifies the signature, upserts the
local mirror tables, and dispatches typed Laravel events:

```php
use Illuminate\Support\Facades\Event;
use Vorgio\Laravel\Events\VorgioInvoicePaid;

Event::listen(VorgioInvoicePaid::class, function (VorgioInvoicePaid $event): void {
    // $event->invoice — Vorgio\Laravel\Models\Invoice (local mirror row)
    // $event->webhookEvent — Vorgio\WebhookEvent (raw, full server payload)
});
```

See `examples/cashier-style.php` for a sketch of the full flow.

## Custom HTTP client

For testing, observability middleware, or alternative HTTP libraries, pass
your own Guzzle-compatible `Psr\Http\Client\ClientInterface` (or, more
specifically, `GuzzleHttp\ClientInterface`):

```php
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;

$mock = new MockHandler([new Response(201, [], '{"data":{}}')]);
$http = new Client(['handler' => HandlerStack::create($mock)]);

$vorgio = new VorgioClient(token: 'act_test', httpClient: $http);
```

## Development

```bash
composer install
vendor/bin/pest                 # run tests
composer format                 # PHP-CS-Fixer
composer stan                   # PHPStan
```

## License

MIT — see [`LICENSE`](LICENSE).

# vorgio-php

Official PHP SDK for the [Vorgio](https://app.vorgio.example) invoicing API.

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
    baseUrl: 'https://app.vorgio.example',
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
| `GET  /v1/clients`                     | `$vorgio->clients()->list($query)`          |
| `POST /v1/clients`                     | `$vorgio->clients()->create($body)`         |
| `GET  /v1/clients/{id}`                | `$vorgio->clients()->retrieve($id)`         |
| `PATCH /v1/clients/{id}`               | `$vorgio->clients()->update($id, $body)`    |
| `DELETE /v1/clients/{id}`              | `$vorgio->clients()->delete($id)`           |

Every method returns the decoded JSON response as a PHP `array`. The full
contract for each request/response shape lives at
[app.vorgio.example/api-reference](https://app.vorgio.example/api-reference).

## Idempotency

`POST` / `PATCH` / `PUT` / `DELETE` requests automatically receive a UUIDv7
`Idempotency-Key` header when you don't provide one — that's the right
default for genuinely new requests. Pass an explicit key when you want safe
replays:

```php
$vorgio->checkouts()->create($body, idempotencyKey: 'wc_order_'.$order->id);
```

If you ship the same idempotency key twice within the server's retention
window, you get back the original response with an
`Idempotency-Replay: true` header.

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
VORGIO_BASE_URL=https://app.vorgio.example
VORGIO_WEBHOOK_SECRET=whsec_…
```

Then resolve the client wherever you need it:

```php
use Vorgio\Laravel\Facades\Vorgio;

Vorgio::checkouts()->create([...]);
```

…or inject `Vorgio\VorgioClient` via the container.

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

<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | API token
    |---------------------------------------------------------------------------
    |
    | Issue tokens from your Vorgio team's "API tokens" page (Teams →
    | Settings → API tokens). Pick the preset that matches your use case:
    | `shop-checkout` for one-shot invoicing (e.g. WooCommerce), or
    | `recurring-billing` for SaaS / membership flows that use the
    | `Vorgio\Laravel\Billable` trait.
    |
    */
    'token' => env('VORGIO_TOKEN'),

    /*
    |---------------------------------------------------------------------------
    | Base URL
    |---------------------------------------------------------------------------
    |
    | Override only when pointing at a self-hosted Vorgio instance or a
    | local development copy. Production users keep the default.
    |
    */
    'base_url' => env('VORGIO_BASE_URL', 'https://vorgio.app'),

    /*
    |---------------------------------------------------------------------------
    | HTTP timeout
    |---------------------------------------------------------------------------
    |
    | Seconds. The Vorgio API responds within ~1s for typical payloads;
    | the default of 30s leaves comfortable headroom.
    |
    */
    'timeout' => (float) env('VORGIO_TIMEOUT', 30),

    /*
    |---------------------------------------------------------------------------
    | Retry policy
    |---------------------------------------------------------------------------
    |
    | The SDK retries up to three times on transport errors and 5xx
    | responses, replaying the same `Idempotency-Key` + body each attempt.
    | Vorgio's server-side Idempotency middleware caches 2xx responses for
    | 24h, so retries are byte-safe by construction. 4xx is never retried.
    |
    */
    'retry' => [
        'enabled' => (bool) env('VORGIO_RETRY', true),
    ],

    /*
    |---------------------------------------------------------------------------
    | Webhooks
    |---------------------------------------------------------------------------
    |
    | When `webhook.secret` is set, the service provider registers a route
    | at `webhook.route` that verifies the HMAC signature, upserts the
    | local `vorgio_invoices` / `vorgio_subscriptions` mirror rows, and
    | dispatches typed Laravel events (`VorgioInvoicePaid`, etc.) for your
    | listeners to consume. Set `webhook.secret` to `null` to disable
    | route registration entirely and handle webhooks yourself.
    |
    */
    'webhook' => [
        'secret' => env('VORGIO_WEBHOOK_SECRET'),
        'route' => env('VORGIO_WEBHOOK_ROUTE', '/vorgio/webhook'),
        'middleware' => ['api'],
        'tolerance_seconds' => 300,
    ],

    /*
    |---------------------------------------------------------------------------
    | Billable model
    |---------------------------------------------------------------------------
    |
    | Optional documentation hint pointing at the Eloquent model that
    | `use Vorgio\Laravel\Billable;`. Not enforced by the package — the
    | trait is what attaches behaviour — but lets `php artisan
    | config:show vorgio` answer the "who is the customer here" question
    | without code-spelunking.
    |
    */
    'billable_model' => env('VORGIO_BILLABLE_MODEL'),

    /*
    |---------------------------------------------------------------------------
    | Table prefix
    |---------------------------------------------------------------------------
    |
    | Override only when the default `vorgio_` prefix collides with an
    | existing table in the host application. Migrations and models read
    | this setting at boot.
    |
    */
    'table_prefix' => 'vorgio_',
];

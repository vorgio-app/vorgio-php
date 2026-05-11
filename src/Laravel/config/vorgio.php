<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | API token
    |---------------------------------------------------------------------------
    |
    | Issue tokens from your Vorgio team's "API tokens" page (Teams →
    | Settings → API tokens). For payment-provider integrations pick the
    | "Payment-provider integration" preset which sets the
    | `checkouts:write` ability and a sensible default rate limit.
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
    'base_url' => env('VORGIO_BASE_URL', 'https://app.vorgio.example'),

    /*
    |---------------------------------------------------------------------------
    | Webhook signing secret
    |---------------------------------------------------------------------------
    |
    | The secret shown once when you registered the webhook endpoint in
    | Vorgio. Used by `Vorgio\Webhooks::constructEvent()` to verify
    | incoming webhook deliveries.
    |
    */
    'webhook_secret' => env('VORGIO_WEBHOOK_SECRET'),

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
];

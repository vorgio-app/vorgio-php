<?php

declare(strict_types=1);

return [
    'labels' => [
        'token' => 'API token',
        'reachable' => 'Reachable & authenticated',
        'mode' => 'Mode',
        'abilities' => 'Token abilities',
        'webhook' => 'Webhook',
        'migrations' => 'Mirror tables',
        'webhook_delivery' => 'Webhook delivery',
    ],

    'token' => [
        'missing' => 'VORGIO_TOKEN is not set.',
        'set' => 'VORGIO_TOKEN is set.',
    ],

    'reachable' => [
        'ok' => 'Vorgio accepted the token.',
        'rejected' => 'Vorgio rejected the request (HTTP :status): :message',
        'unreachable' => 'Could not reach Vorgio: :message',
    ],

    'mode' => [
        'live' => 'Token is in LIVE mode.',
        'test' => 'Token is in TEST mode — test invoices are not emailed and do not bill. Use a LIVE token for production.',
        'unknown' => 'Unknown mode.',
    ],

    'abilities' => [
        'ok' => 'All required abilities are granted.',
        'missing' => 'Missing abilities: :abilities. Re-issue the token with the matching preset.',
    ],

    'webhook' => [
        'no_secret' => 'VORGIO_WEBHOOK_SECRET is not set — this app cannot receive webhooks.',
        'not_registered' => 'No webhook registered for :url. Add one in your Vorgio team settings.',
        'inactive' => 'The webhook for :url is inactive (likely auto-disabled after repeated delivery failures). Re-enable it.',
        'missing_events' => 'Webhook is registered but not subscribed to: :events.',
        'ok' => 'Active webhook registered for :url with the required events.',
    ],

    'migrations' => [
        'ok' => 'All Vorgio mirror tables exist.',
        'missing' => 'Missing tables: :tables. Run `php artisan migrate`.',
    ],

    'webhook_delivery' => [
        'request_failed' => 'Test webhook request failed: :message',
        'none_received' => 'No active webhook received the test event.',
        'rejected_status' => 'Endpoint :url did not accept the test event (HTTP :status). Check the URL and that VORGIO_WEBHOOK_SECRET matches.',
        'rejected_error' => 'Endpoint :url did not accept the test event: :error Check the URL and that VORGIO_WEBHOOK_SECRET matches.',
        'rejected_unknown' => 'Endpoint :url did not accept the test event. Check the URL and that VORGIO_WEBHOOK_SECRET matches.',
        'ok' => 'Your endpoint received and verified a signed test event.',
    ],
];

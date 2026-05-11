<?php

declare(strict_types=1);

/**
 * Smoke example — issue an invoice via the high-level /v1/checkouts endpoint.
 *
 * Usage:
 *
 *   composer install
 *   VORGIO_TOKEN=act_… php examples/checkout.php
 *
 * Optional: VORGIO_BASE_URL=http://localhost:8000  (when pointing at a local Vorgio)
 */

require __DIR__.'/../vendor/autoload.php';

use Vorgio\Exception\VorgioApiException;
use Vorgio\VorgioClient;

$token = getenv('VORGIO_TOKEN') ?: '';
$baseUrl = getenv('VORGIO_BASE_URL') ?: 'https://app.vorgio.example';

if ($token === '') {
    fwrite(STDERR, "Set VORGIO_TOKEN before running this script.\n");
    exit(1);
}

$vorgio = new VorgioClient(token: $token, baseUrl: $baseUrl);

try {
    $result = $vorgio->checkouts()->create([
        'client' => [
            'external_id' => 'demo_customer_'.bin2hex(random_bytes(4)),
            'name' => 'Demo Customer',
            'address' => 'Demo Street 1',
            'zip' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'email' => 'demo@example.test',
            'language' => 'de',
            'rate' => 0,
            'vat' => 19,
            'default_position_mode' => 'fixed',
        ],
        'invoice' => [
            'tax_rate' => 19,
            'due_offset_days' => 14,
            'subject' => 'Test invoice from vorgio-php SDK',
            'positions' => [
                [
                    'id' => '0193f7b0-1b8a-7b7d-9ad0-0c7b5b1d5f3e',
                    'date' => date('Y-m-d'),
                    'mode' => 'fixed',
                    'description' => 'SDK smoke test',
                    'amount_cents' => 9900,
                ],
            ],
        ],
        // `send` is optional — when omitted, Vorgio uses its localized default
        // subject + body, picking the language from `client.language`. Pass
        // your own `subject` / `body` if you want to override.
        'metadata' => [
            'origin' => 'vorgio-php example',
        ],
    ]);

    echo 'Invoice created: '.$result['data']['invoice']['number']."\n";
    echo 'Mail event id:   '.$result['data']['mail_event_id']."\n";
} catch (VorgioApiException $e) {
    fwrite(STDERR, 'Vorgio API error (HTTP '.$e->statusCode.'): '.$e->getMessage()."\n");
    if ($e->problemType() !== null) {
        fwrite(STDERR, '  problem type: '.$e->problemType()."\n");
    }
    exit(1);
}

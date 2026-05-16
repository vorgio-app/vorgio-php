<?php

declare(strict_types=1);

/**
 * Smoke example — start, change, and stop a recurring template via the
 * framework-agnostic Subscriptions resource. Mirrors examples/checkout.php
 * in style.
 *
 * Usage:
 *
 *   composer install
 *   VORGIO_TOKEN=act_… php examples/recurring-billing.php
 *
 * Optional: VORGIO_BASE_URL=http://localhost:8000  (local Vorgio)
 */

require __DIR__.'/../vendor/autoload.php';

use Vorgio\Exception\VorgioApiException;
use Vorgio\Support\OperationDerivation;
use Vorgio\VorgioClient;

$token = getenv('VORGIO_TOKEN') ?: '';
$baseUrl = getenv('VORGIO_BASE_URL') ?: 'https://vorgio.app';

if ($token === '') {
    fwrite(STDERR, "Set VORGIO_TOKEN before running this script.\n");
    exit(1);
}

$vorgio = new VorgioClient(token: $token, baseUrl: $baseUrl);

// One operation id covers every API call this script makes. In a real
// integration you'd persist this id before the first call and replay it
// on retry — that's exactly what the Cashier-style trait does for you.
$opId = OperationDerivation::fromOrGenerate(null)->operationId;

try {
    $started = $vorgio->subscriptions()->start([
        'client' => [
            'external_id' => 'demo_assoc_'.bin2hex(random_bytes(4)),
            'name' => 'Demo Verein',
            'address' => 'Vereinsweg 1',
            'zip' => '10115',
            'city' => 'Berlin',
            'country' => 'DE',
            'email' => 'kasse@demo-verein.test',
            'language' => 'de',
            'rate' => 0,
            'vat' => 19,
            'default_position_mode' => 'fixed',
        ],
        'every' => 'monthly',
        'invoice' => [
            'tax_rate' => 19,
            'due_offset_days' => 14,
            'subject' => 'Mitgliedsbeitrag — Demo Verein',
            'positions' => [
                [
                    'date' => date('Y-m-d'),
                    'mode' => 'fixed',
                    'description' => 'Monatsbeitrag',
                    'amount_cents' => 9900,
                ],
            ],
        ],
        'metadata' => ['origin' => 'vorgio-php recurring example'],
    ], operationId: $opId);

    $templateId = $started['data']['invoice']['id'];
    echo "Subscription started:\n";
    echo "  template id:      $templateId\n";
    echo '  every:            '.$started['data']['invoice']['every']."\n";
    echo '  next_invoice_at:  '.($started['data']['invoice']['next_invoice_at'] ?? '(scheduler will set)')."\n";

    echo "\nSwitching cadence to yearly…\n";
    $vorgio->subscriptions()->changeCycle($templateId, 'yearly', operationId: $opId);

    echo "Stopping recurring generation…\n";
    $vorgio->subscriptions()->stop($templateId, operationId: $opId);

    echo "\nDone. The template is still visible in the Vorgio dashboard with full history.\n";
} catch (VorgioApiException $e) {
    fwrite(STDERR, 'Vorgio API error (HTTP '.$e->statusCode.'): '.$e->getMessage()."\n");
    if ($e->problemType() !== null) {
        fwrite(STDERR, '  problem type: '.$e->problemType()."\n");
    }
    exit(1);
}

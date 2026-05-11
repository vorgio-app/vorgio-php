<?php

declare(strict_types=1);

/**
 * Minimal webhook receiver — drop into any front-controller. Returns 204 on
 * success, 400 on signature failure. Logs the verified event so you can wire
 * it up to your fulfilment.
 *
 * In a real integration this should: enqueue a job, update your order, etc.
 * Keep the work tiny so you can ack within Vorgio's HTTP timeout.
 */

require __DIR__.'/../vendor/autoload.php';

use Vorgio\Exception\VorgioSignatureException;
use Vorgio\Webhooks;

$secret = getenv('VORGIO_WEBHOOK_SECRET') ?: '';
$payload = (string) file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_VORGIO_SIGNATURE'] ?? '';

try {
    $event = Webhooks::constructEvent($payload, $sigHeader, $secret);
} catch (VorgioSignatureException $e) {
    http_response_code(400);
    error_log('Vorgio webhook rejected: '.$e->getMessage());
    exit;
}

switch ($event->type) {
    case 'invoice.sent':
        // e.g. mark order awaiting-payment.
        error_log('invoice.sent for '.($event->data['invoice']['id'] ?? '?'));
        break;

    case 'invoice.paid':
        // e.g. fulfil the order.
        error_log('invoice.paid for '.($event->data['invoice']['id'] ?? '?'));
        break;

    default:
        // Unknown event — ignore but ack.
        break;
}

http_response_code(204);

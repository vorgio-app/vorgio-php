<?php

declare(strict_types=1);

use Vorgio\Exception\VorgioSignatureException;
use Vorgio\WebhookEvent;
use Vorgio\Webhooks;

it('round-trips: sign() output verifies via constructEvent()', function (): void {
    $secret = 'whsec_test';
    $now = 1_715_000_000;
    $payload = json_encode([
        'id' => 'evt_1',
        'type' => 'invoice.sent',
        'created_at' => '2026-05-10T12:00:00Z',
        'data' => ['invoice' => ['id' => 'inv_1', 'number' => 'INV-2026-0001']],
        'metadata' => ['order_id' => 'wc_42'],
    ], JSON_THROW_ON_ERROR);

    $sigHeader = Webhooks::sign($payload, $secret, $now);

    $event = Webhooks::constructEvent($payload, $sigHeader, $secret, now: $now);

    expect($event)->toBeInstanceOf(WebhookEvent::class)
        ->and($event->id)->toBe('evt_1')
        ->and($event->type)->toBe('invoice.sent')
        ->and($event->data['invoice']['id'])->toBe('inv_1')
        ->and($event->metadata)->toBe(['order_id' => 'wc_42'])
        ->and($event->rawPayload)->toBe($payload);
});

it('matches the server signature scheme byte-for-byte', function (): void {
    // Mirror of app/Jobs/DispatchWebhook.php: HMAC-SHA256 over "<ts>.<json>".
    $payload = '{"hello":"world"}';
    $secret = 'whsec_match';
    $ts = 1_700_000_000;

    $expected = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$payload, $secret);

    expect(Webhooks::sign($payload, $secret, $ts))->toBe($expected);
});

it('rejects payload tampering', function (): void {
    $secret = 'whsec_test';
    $now = 1_715_000_000;
    $payload = '{"id":"evt_1","type":"invoice.sent","data":{"amount":100}}';

    $sig = Webhooks::sign($payload, $secret, $now);

    // Tamper with the payload AFTER signing.
    $tampered = str_replace('100', '999', $payload);

    Webhooks::constructEvent($tampered, $sig, $secret, now: $now);
})->throws(VorgioSignatureException::class, 'signature mismatch');

it('rejects a stale timestamp outside the tolerance window', function (): void {
    $secret = 'whsec_test';
    $payload = '{"id":"evt_1","type":"invoice.sent","data":{}}';
    $signedAt = 1_715_000_000;

    $sig = Webhooks::sign($payload, $secret, $signedAt);

    // 10 minutes later — default tolerance is 5 minutes.
    Webhooks::constructEvent($payload, $sig, $secret, tolerance: 300, now: $signedAt + 600);
})->throws(VorgioSignatureException::class, 'tolerance window');

it('rejects future-dated timestamps outside the tolerance window', function (): void {
    $secret = 'whsec_test';
    $payload = '{"id":"evt_1","type":"x","data":{}}';
    $signedAt = 1_715_000_000;
    $sig = Webhooks::sign($payload, $secret, $signedAt);

    // Receiver clock is 10 minutes BEHIND the sender — outside default window.
    Webhooks::constructEvent($payload, $sig, $secret, tolerance: 300, now: $signedAt - 600);
})->throws(VorgioSignatureException::class, 'tolerance window');

it('rejects malformed signature headers', function (): void {
    Webhooks::constructEvent('{}', 'totally-bogus', 'whsec_test');
})->throws(VorgioSignatureException::class, 'malformed');

it('rejects an empty webhook secret', function (): void {
    Webhooks::constructEvent('{}', 't=1,v1=abc', '');
})->throws(VorgioSignatureException::class, 'cannot be empty');

it('rejects payload that is not valid JSON', function (): void {
    $secret = 'whsec_test';
    $now = 1_715_000_000;
    $payload = 'not-json-at-all';
    $sig = Webhooks::sign($payload, $secret, $now);

    Webhooks::constructEvent($payload, $sig, $secret, now: $now);
})->throws(VorgioSignatureException::class, 'not valid JSON');

it('tolerates whitespace inside the signature header', function (): void {
    $secret = 'whsec_test';
    $now = 1_715_000_000;
    $payload = '{"id":"evt_1","type":"invoice.sent","data":{}}';
    $hmac = hash_hmac('sha256', $now.'.'.$payload, $secret);

    $event = Webhooks::constructEvent(
        $payload,
        ' t='.$now.' , v1='.$hmac.' ',
        $secret,
        now: $now,
    );

    expect($event->id)->toBe('evt_1');
});

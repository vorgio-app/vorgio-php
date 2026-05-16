<?php

declare(strict_types=1);

use Vorgio\Util\Uuid;

it('generates canonical 36-char UUIDv7 strings', function (): void {
    $uuid = Uuid::v7();

    expect($uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('produces values that sort lexically by creation time', function (): void {
    $first = Uuid::v7();
    usleep(2000); // 2ms so the millisecond prefix advances
    $second = Uuid::v7();

    expect(strcmp($first, $second))->toBeLessThan(0);
});

it('does not collide for back-to-back generations', function (): void {
    $values = [];
    for ($i = 0; $i < 1_000; $i++) {
        $values[Uuid::v7()] = true;
    }

    expect(count($values))->toBe(1_000);
});

it('derives identical UUIDv5 for identical inputs', function (): void {
    $a = Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, 'op-123:subscribe');
    $b = Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, 'op-123:subscribe');

    expect($a)->toBe($b)
        ->and($a)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('produces different UUIDv5 for different names', function (): void {
    $a = Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, 'op-123:subscribe');
    $b = Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, 'op-123:send');

    expect($a)->not->toBe($b);
});

it('matches the RFC 9562 v5 reference vector for the DNS namespace', function (): void {
    // From RFC 9562 §A.4: uuid5(NS_DNS, "www.example.com")
    $nsDns = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    expect(Uuid::v5($nsDns, 'www.example.com'))
        ->toBe('2ed6657d-e927-568b-95e1-2665a8aea6a2');
});

it('rejects malformed UUID namespaces', function (): void {
    expect(fn () => Uuid::v5('not-a-uuid', 'whatever'))
        ->toThrow(InvalidArgumentException::class);
});

it('extracts the embedded millisecond timestamp from a UUIDv7', function (): void {
    $before = (int) floor(microtime(true) * 1000);
    $uuid = Uuid::v7();
    $after = (int) floor(microtime(true) * 1000);

    $ts = Uuid::extractTimestampMs($uuid);

    expect($ts)->toBeGreaterThanOrEqual($before)
        ->and($ts)->toBeLessThanOrEqual($after);
});

it('rejects non-v7 UUIDs in extractTimestampMs', function (): void {
    $v5 = Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, 'anything');

    expect(fn () => Uuid::extractTimestampMs($v5))
        ->toThrow(InvalidArgumentException::class);
});

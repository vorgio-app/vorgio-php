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

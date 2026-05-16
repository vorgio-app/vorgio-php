<?php

declare(strict_types=1);

use Vorgio\Support\OperationDerivation;
use Vorgio\Util\Uuid;

it('derives identical idempotency keys for the same op id + purpose', function (): void {
    $opId = Uuid::v7();
    $a = new OperationDerivation($opId);
    $b = new OperationDerivation($opId);

    expect($a->idempotencyKey('subscribe'))->toBe($b->idempotencyKey('subscribe'));
});

it('derives different keys for different purposes under one op id', function (): void {
    $opId = Uuid::v7();
    $d = new OperationDerivation($opId);

    expect($d->idempotencyKey('subscribe'))
        ->not->toBe($d->idempotencyKey('send'))
        ->and($d->idempotencyKey('subscribe'))
        ->not->toBe($d->idempotencyKey('cancel'));
});

it('derives different keys for different op ids under one purpose', function (): void {
    $a = new OperationDerivation(Uuid::v7());
    usleep(2000);
    $b = new OperationDerivation(Uuid::v7());

    expect($a->idempotencyKey('subscribe'))->not->toBe($b->idempotencyKey('subscribe'));
});

it('derives stable per-position UUIDs', function (): void {
    $opId = Uuid::v7();
    $d = new OperationDerivation($opId);

    expect($d->positionUuid(0))->toBe((new OperationDerivation($opId))->positionUuid(0))
        ->and($d->positionUuid(0))->not->toBe($d->positionUuid(1));
});

it('snapshot timestamp is stable for one op id and reflects creation time', function (): void {
    $before = (int) floor(microtime(true) * 1000);
    $opId = Uuid::v7();
    $after = (int) floor(microtime(true) * 1000);

    $d = new OperationDerivation($opId);
    $ts = $d->snapshotDateTime();
    $ms = (int) $ts->format('U') * 1000 + (int) $ts->format('v');

    expect($ms)->toBeGreaterThanOrEqual($before)
        ->and($ms)->toBeLessThanOrEqual($after)
        ->and($ts->getTimezone()->getName())->toBe('UTC');

    // Re-derive a second time → identical instant (the load-bearing claim).
    $again = (new OperationDerivation($opId))->snapshotDateTime();
    expect($again->format('U.u'))->toBe($ts->format('U.u'));
});

it('snapshot date string matches Y-m-d of the embedded timestamp in UTC', function (): void {
    $opId = Uuid::v7();
    $d = new OperationDerivation($opId);

    expect($d->snapshotDateString())->toBe($d->snapshotDateTime()->format('Y-m-d'));
});

it('fromOrGenerate accepts a caller-supplied id verbatim', function (): void {
    $opId = Uuid::v7();
    expect(OperationDerivation::fromOrGenerate($opId)->operationId)->toBe($opId);
});

it('fromOrGenerate creates a fresh UUIDv7 when none is provided', function (): void {
    $d = OperationDerivation::fromOrGenerate(null);

    expect($d->operationId)
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});

it('rejects construction from a non-v7 UUID', function (): void {
    $v5 = Uuid::v5(Uuid::NAMESPACE_VORGIO_OP, 'whatever');

    expect(fn () => new OperationDerivation($v5))
        ->toThrow(InvalidArgumentException::class);
});

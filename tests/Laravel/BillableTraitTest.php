<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Event;
use Vorgio\Laravel\Events\VorgioBillingCycleChanged;
use Vorgio\Laravel\Events\VorgioCustomerCreated;
use Vorgio\Laravel\Events\VorgioSubscriptionStarted;
use Vorgio\Laravel\Events\VorgioSubscriptionStopped;
use Vorgio\Laravel\Models\Operation;
use Vorgio\Laravel\Models\Subscription;
use Vorgio\Laravel\Models\VorgioBillable;
use Vorgio\Tests\Laravel\TestAssociation;

it('starts a subscription and persists the polymorphic mapping', function (): void {
    Event::fake([VorgioCustomerCreated::class, VorgioSubscriptionStarted::class]);

    $history = vorgioBindMockClient([
        jsonResponse(201, [
            'data' => [
                'client_id' => 'cli_1',
                'invoice' => ['id' => 'inv_1', 'every' => 'monthly', 'next_invoice_at' => '2026-06-15'],
            ],
        ]),
    ]);

    $assoc = TestAssociation::create(['name' => 'Verein', 'email' => 'kasse@verein.test']);
    $sub = $assoc->subscribe('monthly', [
        'subject' => 'Mitgliedsbeitrag',
        'tax_rate' => 19,
        'positions' => [['mode' => 'fixed', 'amount_cents' => 9900]],
    ]);

    expect($sub)->toBeInstanceOf(Subscription::class)
        ->and($sub->vorgio_invoice_id)->toBe('inv_1')
        ->and($sub->every)->toBe('monthly')
        ->and($sub->isActive())->toBeTrue()
        ->and($assoc->fresh()->vorgioClientId())->toBe('cli_1')
        ->and($assoc->fresh()->hasActiveSubscription())->toBeTrue();

    // Body sent to /v1/checkouts carries `every` + invoice payload.
    /** @var Request $req */
    $req = $history[0]['request'];
    $body = json_decode((string) $req->getBody(), true);

    expect($body['every'])->toBe('monthly')
        ->and($body['invoice']['subject'])->toBe('Mitgliedsbeitrag')
        ->and($req->getHeaderLine('Idempotency-Key'))->not->toBe('');

    Event::assertDispatched(VorgioCustomerCreated::class);
    Event::assertDispatched(VorgioSubscriptionStarted::class);
});

it('is idempotent: calling subscribe twice returns the existing subscription without a second API call', function (): void {
    $history = vorgioBindMockClient([
        jsonResponse(201, [
            'data' => [
                'client_id' => 'cli_1',
                'invoice' => ['id' => 'inv_1'],
            ],
        ]),
    ]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);

    $first = $assoc->subscribe('monthly', ['positions' => []]);
    $second = $assoc->subscribe('monthly', ['positions' => []]);

    expect($second->id)->toBe($first->id)
        ->and(count($history))->toBe(1);
});

it('queue-retry replay: the same persisted Operation produces the same Idempotency-Key on the second attempt', function (): void {
    $history = vorgioBindMockClient([
        // First attempt succeeds at the HTTP layer but we *simulate* the
        // local follow-up crashing before persisting the Subscription —
        // by clearing the Subscription table after the first call. The
        // second attempt should reuse the pending Operation row and hit
        // the cached idempotency replay on the server side.
        jsonResponse(201, ['data' => ['client_id' => 'cli_1', 'invoice' => ['id' => 'inv_1']]]),
        jsonResponse(201, ['data' => ['client_id' => 'cli_1', 'invoice' => ['id' => 'inv_1']]]),
    ]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);

    // First attempt
    $assoc->subscribe('monthly', ['positions' => []]);

    // Simulate "local write crashed mid-onSuccess" by destroying the
    // Subscription + Operation rows, leaving only the pending operation.
    Subscription::query()->delete();
    $operation = Operation::query()->first();
    $operation->update(['status' => Operation::STATUS_PENDING]);

    $opIdBefore = $operation->operation_id;

    // Second attempt picks up the same operation_id
    $assoc->refresh()->subscribe('monthly', ['positions' => []]);

    /** @var Request $first */
    $first = $history[0]['request'];
    /** @var Request $second */
    $second = $history[1]['request'];

    expect($first->getHeaderLine('Idempotency-Key'))->toBe($second->getHeaderLine('Idempotency-Key'))
        ->and(Operation::query()->where('operation_id', $opIdBefore)->count())->toBe(1);
});

it('changes the billing cycle in place via subscriptions()->changeCycle', function (): void {
    Event::fake([VorgioBillingCycleChanged::class]);

    vorgioBindMockClient([
        // start()
        jsonResponse(201, ['data' => ['client_id' => 'cli_1', 'invoice' => ['id' => 'inv_1']]]),
        // changeCycle()
        jsonResponse(200, ['data' => ['id' => 'inv_1', 'every' => 'yearly', 'next_invoice_at' => '2027-05-16']]),
    ]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->subscribe('monthly', ['positions' => []]);

    $updated = $assoc->refresh()->changeBillingCycle('yearly');

    expect($updated->every)->toBe('yearly')
        ->and($updated->next_invoice_at?->toDateString())->toBe('2027-05-16');

    Event::assertDispatched(VorgioBillingCycleChanged::class);
});

it('rejects changeBillingCycle when there is no active subscription', function (): void {
    vorgioBindMockClient([]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);

    expect(fn () => $assoc->changeBillingCycle('yearly'))
        ->toThrow(InvalidArgumentException::class);
});

it('cancelSubscription with stop-only stops the recurring template and does not Storno the child', function (): void {
    Event::fake([VorgioSubscriptionStopped::class]);

    $history = vorgioBindMockClient([
        // start()
        jsonResponse(201, ['data' => ['client_id' => 'cli_1', 'invoice' => ['id' => 'inv_1']]]),
        // stop()
        jsonResponse(200, ['data' => ['id' => 'inv_1', 'recurring_stopped_at' => '2026-05-16T10:00:00Z']]),
    ]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->subscribe('monthly', ['positions' => []]);

    $assoc->refresh()->cancelSubscription('stop-only');

    expect(count($history))->toBe(2)
        ->and((string) $history[1]['request']->getUri())
        ->toBe('https://vorgio.test/api/v1/invoices/inv_1/stop-recurring')
        ->and($assoc->vorgioSubscriptions()->first()->isStopped())->toBeTrue();

    Event::assertDispatched(VorgioSubscriptionStopped::class);
});

it('cancelSubscription is a no-op when there is no active subscription', function (): void {
    $history = vorgioBindMockClient([]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->cancelSubscription('stop-only');

    expect(count($history))->toBe(0);
});

it('rejects unknown childInvoiceStrategy values', function (): void {
    vorgioBindMockClient([
        jsonResponse(201, ['data' => ['client_id' => 'cli_1', 'invoice' => ['id' => 'inv_1']]]),
    ]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->subscribe('monthly', ['positions' => []]);

    expect(fn () => $assoc->refresh()->cancelSubscription('something-weird'))
        ->toThrow(InvalidArgumentException::class);
});

it('createAsVorgioCustomer provisions a Vorgio client and persists the mapping', function (): void {
    Event::fake([VorgioCustomerCreated::class]);

    vorgioBindMockClient([
        jsonResponse(201, ['data' => ['id' => 'cli_42']]),
    ]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $billable = $assoc->createAsVorgioCustomer(['name' => 'X', 'email' => 'x@y.z']);

    expect($billable)->toBeInstanceOf(VorgioBillable::class)
        ->and($billable->vorgio_client_id)->toBe('cli_42')
        ->and($assoc->fresh()->hasVorgioCustomer())->toBeTrue();

    Event::assertDispatched(VorgioCustomerCreated::class);
});

it('createAsVorgioCustomer is idempotent: a second call does not hit the API', function (): void {
    $history = vorgioBindMockClient([
        jsonResponse(201, ['data' => ['id' => 'cli_42']]),
    ]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->createAsVorgioCustomer(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->refresh()->createAsVorgioCustomer(['name' => 'X', 'email' => 'x@y.z']);

    expect(count($history))->toBe(1);
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Vorgio\Laravel\Events\VorgioBillingCycleChanged;
use Vorgio\Laravel\Events\VorgioInvoiceCancelled;
use Vorgio\Laravel\Events\VorgioInvoicePaid;
use Vorgio\Laravel\Events\VorgioInvoiceSent;
use Vorgio\Laravel\Events\VorgioSubscriptionStopped;
use Vorgio\Laravel\Models\Invoice;
use Vorgio\Laravel\Models\Subscription;
use Vorgio\Tests\Laravel\TestAssociation;
use Vorgio\Webhooks;

/**
 * Submit a raw, pre-signed webhook payload to the package's webhook
 * route by handing a Symfony Request directly to the HTTP kernel.
 *
 * Avoids the Laravel test-helper trait so we never have to type-resolve
 * Pest's runtime `$this` binding statically — `app(Kernel::class)` is
 * sufficient and gives a fully-typed return through Larastan stubs.
 */
function postRawWebhook(string $payload, string $signatureHeader): TestResponse
{
    $request = Request::create(
        '/vorgio/webhook',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_VORGIO-SIGNATURE' => $signatureHeader,
            'CONTENT_TYPE' => 'application/json',
        ],
        $payload,
    );

    $response = app(Kernel::class)->handle($request);

    return TestResponse::fromBaseResponse($response);
}

function signedPayload(string $type, array $data): array
{
    $payload = json_encode([
        'id' => 'evt_'.bin2hex(random_bytes(4)),
        'type' => $type,
        'created_at' => '2026-05-16T10:00:00Z',
        'data' => $data,
    ], JSON_THROW_ON_ERROR);

    return [$payload, Webhooks::sign($payload, 'wsec_test')];
}

it('rejects a webhook with a bad signature', function (): void {
    [$payload] = signedPayload('invoice.sent', []);

    $response = postRawWebhook($payload, 't=1234567890,v1=deadbeef');

    expect($response->status())->toBe(400);
});

it('upserts an Invoice mirror row and dispatches VorgioInvoiceSent on invoice.sent', function (): void {
    Event::fake([VorgioInvoiceSent::class]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $billable = $assoc->vorgioBillable()->create(['vorgio_client_id' => 'cli_1']);

    [$payload, $sig] = signedPayload('invoice.sent', [
        'invoice' => [
            'id' => 'inv_1',
            'client_id' => 'cli_1',
            'total_cents' => 9900,
            'currency' => 'EUR',
            'number' => '2026-0001',
            'billing_date' => '2026-05-16',
            'every' => 'monthly',
            'next_invoice_at' => '2026-06-16T00:00:00Z',
            'metadata' => ['association_id' => '01923f4c-aaaa-bbbb-cccc-000000000001'],
            'sent_at' => '2026-05-16T09:30:00Z',
        ],
    ]);

    $response = postRawWebhook($payload, $sig);

    expect($response->status())->toBe(200);

    $mirror = Invoice::query()->where('vorgio_invoice_id', 'inv_1')->first();
    expect($mirror)->not->toBeNull()
        ->and($mirror->vorgio_billable_id)->toBe($billable->id)
        ->and($mirror->status)->toBe(Invoice::STATUS_SENT)
        ->and($mirror->total_cents)->toBe(9900)
        ->and($mirror->number)->toBe('2026-0001')
        ->and($mirror->billing_date?->toDateString())->toBe('2026-05-16')
        ->and($mirror->every)->toBe('monthly')
        ->and($mirror->next_invoice_at?->toDateString())->toBe('2026-06-16')
        ->and($mirror->metadata)->toBe(['association_id' => '01923f4c-aaaa-bbbb-cccc-000000000001']);

    Event::assertDispatched(VorgioInvoiceSent::class);
});

it('marks the mirror row paid and dispatches VorgioInvoicePaid on invoice.paid', function (): void {
    Event::fake([VorgioInvoicePaid::class]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->vorgioBillable()->create(['vorgio_client_id' => 'cli_1']);

    [$payload, $sig] = signedPayload('invoice.paid', [
        'invoice' => [
            'id' => 'inv_1',
            'client_id' => 'cli_1',
            'total_cents' => 9900,
            'paid_at' => '2026-05-17T08:00:00Z',
        ],
    ]);

    $response = postRawWebhook($payload, $sig);

    expect($response->status())->toBe(200);

    $mirror = Invoice::query()->where('vorgio_invoice_id', 'inv_1')->first();
    expect($mirror->status)->toBe(Invoice::STATUS_PAID)
        ->and($mirror->paid_at)->not->toBeNull();

    Event::assertDispatched(VorgioInvoicePaid::class);
});

it('marks the mirror cancelled and dispatches VorgioInvoiceCancelled on invoice.cancelled', function (): void {
    Event::fake([VorgioInvoiceCancelled::class]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->vorgioBillable()->create(['vorgio_client_id' => 'cli_1']);

    [$payload, $sig] = signedPayload('invoice.cancelled', [
        'invoice' => [
            'id' => 'inv_1',
            'client_id' => 'cli_1',
            'total_cents' => 9900,
            'cancelled_at' => '2026-05-17T09:00:00Z',
        ],
    ]);

    postRawWebhook($payload, $sig);

    $mirror = Invoice::query()->where('vorgio_invoice_id', 'inv_1')->first();
    expect($mirror->status)->toBe(Invoice::STATUS_CANCELLED);

    Event::assertDispatched(VorgioInvoiceCancelled::class);
});

it('updates the Subscription mirror on invoice.recurring.stopped', function (): void {
    Event::fake([VorgioSubscriptionStopped::class]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $billable = $assoc->vorgioBillable()->create(['vorgio_client_id' => 'cli_1']);
    $subscription = Subscription::query()->create([
        'vorgio_billable_id' => $billable->id,
        'vorgio_invoice_id' => 'inv_1',
        'every' => 'monthly',
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    [$payload, $sig] = signedPayload('invoice.recurring.stopped', [
        'invoice' => ['id' => 'inv_1'],
        'stopped_at' => '2026-05-17T10:00:00Z',
    ]);

    postRawWebhook($payload, $sig);

    expect($subscription->fresh()->status)->toBe(Subscription::STATUS_STOPPED);
    Event::assertDispatched(VorgioSubscriptionStopped::class);
});

it('updates the Subscription mirror on invoice.recurring.cycle-changed', function (): void {
    Event::fake([VorgioBillingCycleChanged::class]);

    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $billable = $assoc->vorgioBillable()->create(['vorgio_client_id' => 'cli_1']);
    $subscription = Subscription::query()->create([
        'vorgio_billable_id' => $billable->id,
        'vorgio_invoice_id' => 'inv_1',
        'every' => 'monthly',
        'status' => Subscription::STATUS_ACTIVE,
    ]);

    [$payload, $sig] = signedPayload('invoice.recurring.cycle-changed', [
        'invoice' => ['id' => 'inv_1', 'every' => 'yearly', 'next_invoice_at' => '2027-05-16'],
    ]);

    postRawWebhook($payload, $sig);

    expect($subscription->fresh()->every)->toBe('yearly');
    Event::assertDispatched(VorgioBillingCycleChanged::class);
});

it('re-delivery of the same invoice event is idempotent (updateOrCreate, single row)', function (): void {
    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->vorgioBillable()->create(['vorgio_client_id' => 'cli_1']);

    [$payload, $sig] = signedPayload('invoice.sent', [
        'invoice' => ['id' => 'inv_1', 'client_id' => 'cli_1', 'total_cents' => 9900],
    ]);

    postRawWebhook($payload, $sig);
    postRawWebhook($payload, $sig);

    expect(Invoice::query()->where('vorgio_invoice_id', 'inv_1')->count())->toBe(1);
});

it('skips webhooks for unknown clients (race with subscribe local-write)', function (): void {
    [$payload, $sig] = signedPayload('invoice.sent', [
        'invoice' => ['id' => 'inv_1', 'client_id' => 'cli_unknown'],
    ]);

    $response = postRawWebhook($payload, $sig);

    expect($response->status())->toBe(200)
        ->and(Invoice::query()->count())->toBe(0);
});

it('ignores unknown event types without crashing', function (): void {
    $assoc = TestAssociation::create(['name' => 'X', 'email' => 'x@y.z']);
    $assoc->vorgioBillable()->create(['vorgio_client_id' => 'cli_1']);

    [$payload, $sig] = signedPayload('something.unknown', ['foo' => 'bar']);

    $response = postRawWebhook($payload, $sig);

    expect($response->status())->toBe(200);
});

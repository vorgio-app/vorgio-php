<?php

declare(strict_types=1);

namespace Vorgio\Laravel;

use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Throwable;
use Vorgio\Exception\VorgioApiException;
use Vorgio\Laravel\Events\VorgioBillingCycleChanged;
use Vorgio\Laravel\Events\VorgioCustomerCreated;
use Vorgio\Laravel\Events\VorgioSubscriptionStarted;
use Vorgio\Laravel\Events\VorgioSubscriptionStopped;
use Vorgio\Laravel\Models\Invoice;
use Vorgio\Laravel\Models\Operation;
use Vorgio\Laravel\Models\Subscription;
use Vorgio\Laravel\Models\VorgioBillable;
use Vorgio\Util\Uuid;
use Vorgio\VorgioClient;

/**
 * Cashier-style billing for Vorgio.
 *
 * Add `use Vorgio\Laravel\Billable;` to any Eloquent model that
 * represents a paying customer (typically `User` or, in MVGV's case,
 * `Association`) and the model gains:
 *
 *   - `subscribe()`, `changeBillingCycle()`, `cancelSubscription()` —
 *     idempotent, queue-retry-safe wrappers around the Vorgio API.
 *   - `createAsVorgioCustomer()` for provisioning a Vorgio client without
 *     also issuing an invoice (useful for "save card" / pre-billing flows).
 *   - `vorgioBillable`, `vorgioSubscriptions`, `vorgioInvoices` relations
 *     pointing at the Vorgio-owned mirror tables.
 *
 * The operation-id state machine that makes retries safe lives entirely
 * inside this trait — consumers never construct `Idempotency-Key` strings
 * by hand. See `vorgio_operations` for the persistence layer.
 */
trait Billable
{
    /**
     * @return MorphOne<VorgioBillable, $this>
     */
    public function vorgioBillable(): MorphOne
    {
        return $this->morphOne(VorgioBillable::class, 'billable');
    }

    /**
     * @return HasManyThrough<Subscription, VorgioBillable, $this>
     */
    public function vorgioSubscriptions(): HasManyThrough
    {
        return $this->hasManyThrough(
            Subscription::class,
            VorgioBillable::class,
            'billable_id',
            'vorgio_billable_id',
            $this->getKeyName(),
            'id',
        )->where(
            'billable_type',
            $this->getMorphClass(),
        );
    }

    /**
     * @return HasManyThrough<Invoice, VorgioBillable, $this>
     */
    public function vorgioInvoices(): HasManyThrough
    {
        return $this->hasManyThrough(
            Invoice::class,
            VorgioBillable::class,
            'billable_id',
            'vorgio_billable_id',
            $this->getKeyName(),
            'id',
        )->where(
            'billable_type',
            $this->getMorphClass(),
        );
    }

    /**
     * @return HasOneThrough<Subscription, VorgioBillable, $this>
     */
    public function vorgioSubscription(): HasOneThrough
    {
        return $this->hasOneThrough(
            Subscription::class,
            VorgioBillable::class,
            'billable_id',
            'vorgio_billable_id',
            $this->getKeyName(),
            'id',
        )->where(
            'billable_type',
            $this->getMorphClass(),
        )->where(
            'status',
            Subscription::STATUS_ACTIVE,
        );
    }

    public function hasVorgioCustomer(): bool
    {
        return $this->vorgioBillable()->exists();
    }

    public function vorgioClientId(): ?string
    {
        return $this->vorgioBillable()->first()?->vorgio_client_id;
    }

    public function hasActiveSubscription(): bool
    {
        return $this->vorgioSubscription()->exists();
    }

    /**
     * Latest invoice in the `sent` state for this billable — i.e. issued
     * but not yet paid. Returns the Vorgio invoice id, not the local mirror
     * row id. `null` when there's nothing to act on.
     */
    public function latestOpenInvoiceId(): ?string
    {
        $invoice = $this->vorgioInvoices()
            ->where('status', Invoice::STATUS_SENT)
            ->orderByDesc('sent_at')
            ->first();

        return $invoice?->vorgio_invoice_id;
    }

    /**
     * Provision a Vorgio client for this billable without issuing an
     * invoice. Idempotent: re-calling after success returns the existing
     * VorgioBillable row.
     *
     * @param  array<string, mixed>  $clientPayload  The `/v1/clients` body.
     */
    public function createAsVorgioCustomer(array $clientPayload): VorgioBillable
    {
        $existing = $this->vorgioBillable()->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->runOperation(
            purpose: Operation::PURPOSE_CREATE_CUSTOMER,
            apiCall: fn (VorgioClient $client, string $opId) => $client->clients()->create(
                $clientPayload,
                operationId: $opId,
            ),
            onSuccess: function (array $response): VorgioBillable {
                $clientId = (string) ($response['data']['id'] ?? $response['id']);

                return $this->upsertVorgioBillable($clientId);
            },
        );
    }

    /**
     * Start a recurring subscription.
     *
     * @param  string  $every  e.g. `'monthly'`, `'yearly'`.
     * @param  array<string, mixed>  $invoicePayload  The `invoice` shape
     *   Vorgio expects (subject, tax_rate, positions, ...).
     * @param  array<string, mixed>  $clientPayload  Optional. When omitted
     *   the trait derives client name + email from the model. Pass
     *   explicitly for full control.
     */
    public function subscribe(string $every, array $invoicePayload, array $clientPayload = []): Subscription
    {
        // Idempotent: if there's already an active subscription for this
        // billable, return it. Closes the "succeeded but queue retried"
        // window without forcing the caller to think about it.
        $existing = $this->vorgioSubscription()->first();
        if ($existing !== null) {
            return $existing;
        }

        $payload = [
            'client' => $clientPayload === [] ? $this->defaultClientPayload() : $clientPayload,
            'every' => $every,
            'invoice' => $invoicePayload,
        ];

        return $this->runOperation(
            purpose: Operation::PURPOSE_SUBSCRIBE,
            apiCall: fn (VorgioClient $client, string $opId) => $client->subscriptions()->start(
                $payload,
                operationId: $opId,
            ),
            onSuccess: function (array $response) use ($every): Subscription {
                $data = $response['data'] ?? $response;
                $clientId = (string) ($data['client_id'] ?? $data['client']['id']);
                $invoice = $data['invoice'] ?? [];
                $invoiceId = (string) ($invoice['id'] ?? '');

                $billable = $this->upsertVorgioBillable($clientId);

                $subscription = Subscription::query()->create([
                    'vorgio_billable_id' => $billable->id,
                    'vorgio_invoice_id' => $invoiceId,
                    'every' => $every,
                    'next_invoice_at' => $invoice['next_invoice_at'] ?? null,
                    'status' => Subscription::STATUS_ACTIVE,
                    'started_at' => now(),
                ]);

                Event::dispatch(new VorgioSubscriptionStarted($subscription));

                return $subscription;
            },
        );
    }

    /**
     * Change the cadence of the currently active subscription in place.
     */
    public function changeBillingCycle(string $every, ?string $nextInvoiceAt = null): Subscription
    {
        $subscription = $this->vorgioSubscription()->first();
        if ($subscription === null) {
            throw new InvalidArgumentException(
                'No active Vorgio subscription on this billable. Call subscribe() first.',
            );
        }

        return $this->runOperation(
            purpose: Operation::PURPOSE_CHANGE_CYCLE,
            apiCall: fn (VorgioClient $client, string $opId) => $client->subscriptions()->changeCycle(
                $subscription->vorgio_invoice_id,
                $every,
                nextInvoiceAt: $nextInvoiceAt,
                operationId: $opId,
            ),
            onSuccess: function (array $response) use ($subscription, $every, $nextInvoiceAt): Subscription {
                $data = $response['data'] ?? $response;

                $subscription->update([
                    'every' => $every,
                    'next_invoice_at' => $data['next_invoice_at'] ?? $nextInvoiceAt ?? $subscription->next_invoice_at,
                ]);

                $subscription->refresh();
                Event::dispatch(new VorgioBillingCycleChanged($subscription));

                return $subscription;
            },
        );
    }

    /**
     * End the recurring arrangement.
     *
     * @param  string  $childInvoiceStrategy  How to treat the currently-open
     *   child invoice (if any):
     *   - `'stop-only'` (default): stop future generation only; leave the
     *      latest child standing. Customer pays out the current period.
     *   - `'storno-always'`: also issue a Stornorechnung for the open child
     *      regardless of payment status. Strongest "everything reversed"
     *      semantics; may trigger a refund obligation if the customer
     *      already paid.
     *   - `'storno-if-unpaid'`: Stornorechnung only when the open child is
     *      still in `sent` (unpaid). Common SaaS default — customer keeps
     *      what they paid for, no refund.
     */
    public function cancelSubscription(string $childInvoiceStrategy = 'stop-only'): void
    {
        $subscription = $this->vorgioSubscription()->first();
        if ($subscription === null) {
            return;
        }

        if (! in_array($childInvoiceStrategy, ['stop-only', 'storno-always', 'storno-if-unpaid'], true)) {
            throw new InvalidArgumentException(
                'Unknown child-invoice strategy: '.$childInvoiceStrategy,
            );
        }

        $this->runOperation(
            purpose: Operation::PURPOSE_CANCEL,
            apiCall: function (VorgioClient $client, string $opId) use ($subscription, $childInvoiceStrategy): array {
                $stop = $client->subscriptions()->stop(
                    $subscription->vorgio_invoice_id,
                    operationId: $opId,
                );

                if ($childInvoiceStrategy === 'stop-only') {
                    return $stop;
                }

                $openInvoiceId = $this->latestOpenInvoiceId();
                if ($openInvoiceId === null) {
                    return $stop;
                }

                // After the stop-only early-return above, $childInvoiceStrategy
                // is either 'storno-always' or 'storno-if-unpaid'.
                if ($childInvoiceStrategy === 'storno-always' || $this->latestOpenChildIsUnpaid()) {
                    // The stop + cancel pair share one operation id, so the
                    // Idempotency-Key for the cancel is derived as a
                    // sub-key — different purpose tag, same operation.
                    $client->invoices()->cancel($openInvoiceId, operationId: $opId);
                }

                return $stop;
            },
            onSuccess: function () use ($subscription): void {
                $subscription->update([
                    'status' => Subscription::STATUS_STOPPED,
                    'stopped_at' => now(),
                ]);

                Event::dispatch(new VorgioSubscriptionStopped($subscription->refresh()));
            },
        );
    }

    /**
     * Default `client` payload Vorgio receives when the caller doesn't
     * pass one explicitly. Override on the consumer model when the
     * derived fields aren't a good fit (e.g. the customer name lives in a
     * relation rather than on the model itself).
     *
     * @return array<string, mixed>
     */
    protected function defaultClientPayload(): array
    {
        return array_filter([
            'external_id' => (string) $this->getKey(),
            'name' => $this->vorgio_client_name ?? $this->name ?? null,
            'email' => $this->vorgio_client_email ?? $this->email ?? null,
        ], fn ($v) => $v !== null);
    }

    private function latestOpenChildIsUnpaid(): bool
    {
        $invoice = $this->vorgioInvoices()
            ->where('status', Invoice::STATUS_SENT)
            ->orderByDesc('sent_at')
            ->first();

        return $invoice !== null && $invoice->paid_at === null;
    }

    private function upsertVorgioBillable(string $vorgioClientId): VorgioBillable
    {
        $billable = $this->vorgioBillable()->first();
        if ($billable !== null) {
            if ($billable->vorgio_client_id !== $vorgioClientId) {
                $billable->update(['vorgio_client_id' => $vorgioClientId]);
            }

            return $billable;
        }

        $created = $this->vorgioBillable()->create([
            'vorgio_client_id' => $vorgioClientId,
        ]);

        Event::dispatch(new VorgioCustomerCreated($created));

        return $created;
    }

    /**
     * Shared scaffolding around a single Vorgio API call: find-or-create a
     * pending Operation row, run the SDK call, persist on success, mark
     * the Operation as completed/failed. The Operation row carries the
     * `operation_id` UUIDv7 that derives every Idempotency-Key — so a
     * queue retry that re-enters this method picks up the same id and
     * therefore the same key, replaying the cached 2xx instead of creating
     * a duplicate side-effect.
     *
     * @template TResult
     *
     * @param  callable(VorgioClient $client, string $operationId): array<string, mixed>  $apiCall
     * @param  callable(array<string, mixed> $response): TResult  $onSuccess
     * @return TResult
     */
    private function runOperation(string $purpose, callable $apiCall, callable $onSuccess): mixed
    {
        $billableType = $this->getMorphClass();
        $billableId = $this->getKey();

        $operation = DB::transaction(function () use ($billableType, $billableId, $purpose) {
            $existing = Operation::query()
                ->where('billable_type', $billableType)
                ->where('billable_id', $billableId)
                ->where('purpose', $purpose)
                ->where('status', Operation::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->increment('attempts');
                $existing->update(['last_attempted_at' => now()]);

                return $existing;
            }

            return Operation::query()->create([
                'billable_type' => $billableType,
                'billable_id' => $billableId,
                'purpose' => $purpose,
                'operation_id' => Uuid::v7(),
                'status' => Operation::STATUS_PENDING,
                'attempts' => 1,
                'last_attempted_at' => now(),
            ]);
        });

        $client = app(VorgioClient::class);

        try {
            $response = $apiCall($client, $operation->operation_id);
        } catch (VorgioApiException $e) {
            // 4xx: not a transport hiccup. Mark the operation failed so
            // queue retries don't keep banging on it forever.
            $operation->update([
                'status' => Operation::STATUS_FAILED,
                'completed_at' => now(),
            ]);

            throw $e;
        } catch (Throwable $e) {
            // Transport-level failures: let the queue worker decide
            // whether to retry. The Operation row stays pending so the
            // next attempt picks up the same operation_id.
            throw $e;
        }

        return DB::transaction(function () use ($onSuccess, $response, $operation) {
            $result = $onSuccess($response);

            $operation->update([
                'status' => Operation::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return $result;
        });
    }
}

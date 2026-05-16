<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Vorgio\Exception\VorgioSignatureException;
use Vorgio\Laravel\Events\VorgioBillingCycleChanged;
use Vorgio\Laravel\Events\VorgioInvoiceCancelled;
use Vorgio\Laravel\Events\VorgioInvoicePaid;
use Vorgio\Laravel\Events\VorgioInvoiceSent;
use Vorgio\Laravel\Events\VorgioSubscriptionStopped;
use Vorgio\Laravel\Models\Invoice;
use Vorgio\Laravel\Models\Subscription;
use Vorgio\Laravel\Models\VorgioBillable;
use Vorgio\WebhookEvent;
use Vorgio\Webhooks;

/**
 * Verifies inbound Vorgio webhooks, upserts the local mirror tables, and
 * dispatches typed Laravel events for consumer listeners to subscribe to.
 *
 * Wired up by `VorgioServiceProvider::boot()` at the configured
 * `webhook.route`. Returns 200 on success, 400 on signature failure, 422
 * when the payload is well-formed but references an unknown billable.
 */
final class WebhookController
{
    public function __invoke(Request $request): Response
    {
        $secret = (string) config('vorgio.webhook.secret', '');
        if ($secret === '') {
            return new Response('Vorgio webhook secret is not configured.', 500);
        }

        try {
            $event = Webhooks::constructEvent(
                payload: $request->getContent(),
                sigHeader: $request->headers->get('Vorgio-Signature', ''),
                secret: $secret,
                tolerance: (int) config('vorgio.webhook.tolerance_seconds', Webhooks::DEFAULT_TOLERANCE),
            );
        } catch (VorgioSignatureException $e) {
            return new Response($e->getMessage(), 400);
        }

        $this->handle($event);

        return new Response('', 200);
    }

    private function handle(WebhookEvent $event): void
    {
        $invoice = $event->data['invoice'] ?? null;
        $vorgioInvoiceId = is_array($invoice) ? (string) ($invoice['id'] ?? '') : '';

        match ($event->type) {
            'invoice.sent' => $this->onInvoiceSent($event, $invoice ?? []),
            'invoice.paid' => $this->onInvoicePaid($event, $invoice ?? []),
            'invoice.cancelled' => $this->onInvoiceCancelled($event, $invoice ?? []),
            'invoice.recurring.stopped' => $this->onSubscriptionStopped($event, $vorgioInvoiceId),
            'invoice.recurring.cycle-changed' => $this->onBillingCycleChanged($event, $vorgioInvoiceId, $invoice ?? []),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     */
    private function onInvoiceSent(WebhookEvent $event, array $invoiceData): void
    {
        $local = $this->upsertInvoiceMirror($invoiceData, [
            'status' => Invoice::STATUS_SENT,
            'sent_at' => $invoiceData['sent_at'] ?? now(),
        ]);

        if ($local !== null) {
            Event::dispatch(new VorgioInvoiceSent($local, $event));
        }
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     */
    private function onInvoicePaid(WebhookEvent $event, array $invoiceData): void
    {
        $local = $this->upsertInvoiceMirror($invoiceData, [
            'status' => Invoice::STATUS_PAID,
            'paid_at' => $invoiceData['paid_at'] ?? now(),
        ]);

        if ($local !== null) {
            Event::dispatch(new VorgioInvoicePaid($local, $event));
        }
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     */
    private function onInvoiceCancelled(WebhookEvent $event, array $invoiceData): void
    {
        $local = $this->upsertInvoiceMirror($invoiceData, [
            'status' => Invoice::STATUS_CANCELLED,
            'cancelled_at' => $invoiceData['cancelled_at'] ?? now(),
        ]);

        if ($local !== null) {
            Event::dispatch(new VorgioInvoiceCancelled($local, $event));
        }
    }

    private function onSubscriptionStopped(WebhookEvent $event, string $vorgioInvoiceId): void
    {
        $subscription = Subscription::query()
            ->where('vorgio_invoice_id', $vorgioInvoiceId)
            ->first();

        if ($subscription === null) {
            return;
        }

        $subscription->update([
            'status' => Subscription::STATUS_STOPPED,
            'stopped_at' => $event->data['stopped_at'] ?? now(),
        ]);

        Event::dispatch(new VorgioSubscriptionStopped($subscription->refresh(), $event));
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     */
    private function onBillingCycleChanged(WebhookEvent $event, string $vorgioInvoiceId, array $invoiceData): void
    {
        $subscription = Subscription::query()
            ->where('vorgio_invoice_id', $vorgioInvoiceId)
            ->first();

        if ($subscription === null) {
            return;
        }

        $subscription->update([
            'every' => $invoiceData['every'] ?? $subscription->every,
            'next_invoice_at' => $invoiceData['next_invoice_at'] ?? $subscription->next_invoice_at,
        ]);

        Event::dispatch(new VorgioBillingCycleChanged($subscription->refresh(), $event));
    }

    /**
     * @param  array<string, mixed>  $invoiceData
     * @param  array<string, mixed>  $attributes
     */
    private function upsertInvoiceMirror(array $invoiceData, array $attributes): ?Invoice
    {
        $vorgioInvoiceId = (string) ($invoiceData['id'] ?? '');
        if ($vorgioInvoiceId === '') {
            return null;
        }

        $clientId = (string) ($invoiceData['client_id'] ?? '');
        if ($clientId === '') {
            return null;
        }

        $billable = VorgioBillable::query()
            ->where('vorgio_client_id', $clientId)
            ->first();

        if ($billable === null) {
            // The webhook references a client we don't know about — most
            // likely a race where the webhook arrived before the
            // subscribe() local-write committed. Skip silently; Vorgio
            // will redeliver and the second pass will find the row.
            return null;
        }

        $totalCents = (int) ($invoiceData['total_cents'] ?? 0);
        $currency = (string) ($invoiceData['currency'] ?? 'EUR');
        $parentInvoiceId = isset($invoiceData['parent_invoice_id'])
            ? (string) $invoiceData['parent_invoice_id']
            : null;

        return Invoice::query()->updateOrCreate(
            ['vorgio_invoice_id' => $vorgioInvoiceId],
            array_merge([
                'vorgio_billable_id' => $billable->id,
                'parent_invoice_id' => $parentInvoiceId,
                'total_cents' => $totalCents,
                'currency' => $currency,
            ], $attributes),
        );
    }
}

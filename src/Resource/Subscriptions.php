<?php

declare(strict_types=1);

namespace Vorgio\Resource;

/**
 * Recurring billing.
 *
 * `start()` provisions a recurring template via the composite
 * `/v1/checkouts` endpoint (which creates the client, the template
 * invoice, and sends it in one call). `changeCycle()` and `stop()` mutate
 * an existing template in place, without deleting it — so the operator's
 * audit trail stays intact.
 *
 * For one-shot invoicing (no recurrence) keep using {@see Invoices} and
 * {@see Checkouts}; subscriptions are only for `every`-bearing flows.
 */
final class Subscriptions extends AbstractResource
{
    /**
     * Provision a new recurring template.
     *
     * The payload mirrors `/v1/checkouts`'s shape (`client`, `invoice`,
     * optional `send`, optional `metadata`) with the addition of `every` —
     * a cadence such as `'monthly'` or `'yearly'`. The server creates the
     * client (find-or-create on `client.external_id` if present), issues
     * the first invoice, and schedules subsequent ones at the configured
     * cadence.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function start(array $payload, ?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/checkouts',
            $payload,
            [],
            $this->idempotencyHeader($operationId, 'subscription.start'),
        );
    }

    /**
     * Change the cadence (`every`) of an existing recurring template in
     * place. Optionally also reset `next_invoice_at`. No new invoice is
     * issued by this call — the next regularly-scheduled one will use the
     * updated cadence.
     *
     * @return array<string, mixed>
     */
    public function changeCycle(
        string $invoiceId,
        string $every,
        ?string $nextInvoiceAt = null,
        ?string $operationId = null,
    ): array {
        $payload = ['every' => $every];
        if ($nextInvoiceAt !== null) {
            $payload['next_invoice_at'] = $nextInvoiceAt;
        }

        return $this->request(
            'POST',
            '/invoices/'.rawurlencode($invoiceId).'/change-cycle',
            $payload,
            [],
            $this->idempotencyHeader($operationId, 'subscription.change-cycle'),
        );
    }

    /**
     * Stop the recurring template without deleting it: the existing
     * invoice stays visible in the operator dashboard with full history,
     * but no further scheduled invoices are issued. Already-issued child
     * invoices are unaffected — cancel them separately via
     * {@see Invoices::cancel()} if a Stornorechnung is needed.
     *
     * @return array<string, mixed>
     */
    public function stop(string $invoiceId, ?string $operationId = null): array
    {
        return $this->request(
            'POST',
            '/invoices/'.rawurlencode($invoiceId).'/stop-recurring',
            [],
            [],
            $this->idempotencyHeader($operationId, 'subscription.stop'),
        );
    }
}

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('loads all Vorgio-owned tables from the package migrations', function (): void {
    expect(Schema::hasTable('vorgio_billables'))->toBeTrue()
        ->and(Schema::hasTable('vorgio_subscriptions'))->toBeTrue()
        ->and(Schema::hasTable('vorgio_operations'))->toBeTrue()
        ->and(Schema::hasTable('vorgio_invoices'))->toBeTrue();
});

it('vorgio_billables has the polymorphic shape', function (): void {
    expect(Schema::hasColumns('vorgio_billables', [
        'id', 'billable_type', 'billable_id', 'vorgio_client_id', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('vorgio_operations is polymorphic on the consumer model', function (): void {
    expect(Schema::hasColumns('vorgio_operations', [
        'id', 'billable_type', 'billable_id', 'purpose', 'operation_id',
        'status', 'attempts', 'last_attempted_at', 'completed_at',
    ]))->toBeTrue();
});

it('vorgio_subscriptions tracks every / next_invoice_at / status', function (): void {
    expect(Schema::hasColumns('vorgio_subscriptions', [
        'id', 'vorgio_billable_id', 'vorgio_invoice_id', 'every',
        'next_invoice_at', 'status', 'started_at', 'stopped_at', 'cancelled_at',
    ]))->toBeTrue();
});

it('vorgio_invoices mirrors invoice state and supports parent_invoice_id back-reference', function (): void {
    expect(Schema::hasColumns('vorgio_invoices', [
        'id', 'vorgio_billable_id', 'vorgio_invoice_id', 'parent_invoice_id',
        'status', 'total_cents', 'currency', 'sent_at', 'paid_at', 'cancelled_at',
    ]))->toBeTrue();
});

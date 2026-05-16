<?php

declare(strict_types=1);

/**
 * Sketch — what the Cashier-style trait flow looks like from the
 * consumer's POV. This file is documentation only; it isn't runnable
 * standalone because it depends on a bootstrapped Laravel app.
 *
 * Inside your Laravel application:
 *
 *   1. `composer require vorgio-app/vorgio-php`
 *   2. Set VORGIO_TOKEN + VORGIO_WEBHOOK_SECRET in `.env`.
 *   3. Run `php artisan migrate` — the package's migrations are auto-loaded.
 *   4. Add `use Vorgio\Laravel\Billable;` to the model that represents a
 *      paying customer (User, Association, Tenant, …).
 *   5. Use the trait methods below.
 */

use Illuminate\Database\Eloquent\Model;
use Vorgio\Laravel\Billable;

// In your app:
class Association extends Model
{
    use Billable;

    // The trait derives `name` and `email` from your model when you
    // call subscribe() without passing a client payload explicitly.
    // Override defaultClientPayload() if those fields live elsewhere.
}

$assoc = Association::find(1);

// Start a monthly recurring billing arrangement. Creates the Vorgio
// client + recurring template + sends the first invoice in one call.
$subscription = $assoc->subscribe('monthly', [
    'subject' => 'Mitgliedsbeitrag',
    'tax_rate' => 19,
    'due_offset_days' => 14,
    'positions' => [
        ['mode' => 'fixed', 'description' => 'Monatsbeitrag', 'amount_cents' => 9900],
    ],
    'metadata' => ['association_id' => $assoc->id],
]);

// Idempotent: calling again returns the existing subscription without
// hitting the API.
$same = $assoc->subscribe('monthly', [/* … */]);
assert($same->id === $subscription->id);

// Change cadence in place. Vorgio updates the template; no new invoice
// is issued by this call.
$assoc->changeBillingCycle('yearly');

// End the arrangement. Choose one of:
//
//   'stop-only'        — just stop generating future invoices. Customer
//                         pays out the current period normally.
//   'storno-always'    — also issue a Stornorechnung for the latest open
//                         child invoice. Strongest reversal semantics.
//   'storno-if-unpaid' — Stornorechnung only when the open child is
//                         still unpaid. Common SaaS default.
$assoc->cancelSubscription('storno-if-unpaid');

// Helpers:
$assoc->hasVorgioCustomer();         // bool
$assoc->vorgioClientId();            // ?string
$assoc->hasActiveSubscription();     // bool
$assoc->latestOpenInvoiceId();       // ?string — Vorgio invoice id

// Relations (all pointing at the Vorgio-owned mirror tables):
$assoc->vorgioBillable;              // Vorgio\Laravel\Models\VorgioBillable
$assoc->vorgioSubscriptions;         // HasManyThrough → Subscription
$assoc->vorgioInvoices;              // HasManyThrough → Invoice (webhook mirror)

// Listen to typed events for any app-specific business logic — the
// webhook controller dispatches these when Vorgio confirms the event.
use Illuminate\Support\Facades\Event;
use Vorgio\Laravel\Events\VorgioInvoicePaid;

Event::listen(VorgioInvoicePaid::class, function (VorgioInvoicePaid $event): void {
    // $event->invoice  → Vorgio\Laravel\Models\Invoice (local mirror row)
    // $event->webhookEvent  → Vorgio\WebhookEvent (raw, with full server payload)
    // …mark the consumer-side state as paid, send a thank-you email, …
});

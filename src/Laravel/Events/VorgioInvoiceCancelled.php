<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Events;

use Vorgio\Laravel\Models\Invoice;
use Vorgio\WebhookEvent;

final class VorgioInvoiceCancelled
{
    public function __construct(
        public readonly Invoice $invoice,
        public readonly WebhookEvent $webhookEvent,
    ) {
    }
}

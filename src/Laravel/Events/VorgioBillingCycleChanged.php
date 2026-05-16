<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Events;

use Vorgio\Laravel\Models\Subscription;
use Vorgio\WebhookEvent;

final class VorgioBillingCycleChanged
{
    public function __construct(
        public readonly Subscription $subscription,
        public readonly ?WebhookEvent $webhookEvent = null,
    ) {
    }
}

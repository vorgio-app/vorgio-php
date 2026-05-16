<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Events;

use Vorgio\Laravel\Models\Subscription;

final class VorgioSubscriptionStarted
{
    public function __construct(public readonly Subscription $subscription)
    {
    }
}

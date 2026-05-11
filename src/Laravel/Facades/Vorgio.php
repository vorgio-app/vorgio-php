<?php

declare(strict_types=1);

namespace Vorgio\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Vorgio\Resource\Checkouts;
use Vorgio\Resource\Clients;
use Vorgio\Resource\Invoices;
use Vorgio\VorgioClient;

/**
 * @method static Checkouts checkouts()
 * @method static Invoices invoices()
 * @method static Clients clients()
 * @method static array<string, mixed> request(string $method, string $path, ?array $body = null, array $query = [], array $headers = [])
 *
 * @see VorgioClient
 */
class Vorgio extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VorgioClient::class;
    }
}
